#[cfg(not(unix))]
compile_error!("navi-brain-supervisor currently requires Unix process groups and file locks");

use std::env;
use std::fs::{self, File, OpenOptions};
use std::io::{self, BufRead, BufReader, Write};
use std::os::fd::AsRawFd;
use std::os::unix::fs::{OpenOptionsExt, PermissionsExt};
use std::os::unix::process::CommandExt;
use std::path::{Path, PathBuf};
use std::process::{Child, Command, ExitStatus, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::{self, RecvTimeoutError, Sender};
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

const LOCK_EX: i32 = 2;
const LOCK_NB: i32 = 4;
const LOCK_UN: i32 = 8;
const SIGINT: i32 = 2;
const SIGTERM: i32 = 15;
const SIGKILL: i32 = 9;

static RUNNING: AtomicBool = AtomicBool::new(true);

unsafe extern "C" {
    fn flock(fd: i32, operation: i32) -> i32;
    fn kill(pid: i32, signal: i32) -> i32;
    fn signal(signal: i32, handler: usize) -> usize;
}

extern "C" fn stop_signal(_: i32) {
    RUNNING.store(false, Ordering::SeqCst);
}

#[derive(Debug)]
struct Config {
    heartbeat: PathBuf,
    health_file: PathBuf,
    lock_file: PathBuf,
    stall_timeout: Duration,
    max_backoff: Duration,
    check: bool,
}

impl Config {
    fn parse() -> Result<Self, String> {
        let root = env::current_dir().map_err(|error| error.to_string())?;
        let mut config = Self {
            heartbeat: root.join("bin/navi-brain-heartbeat"),
            health_file: root.join("var/supervisor-health.json"),
            lock_file: root.join("var/supervisor.lock"),
            stall_timeout: Duration::from_secs(480),
            max_backoff: Duration::from_secs(60),
            check: false,
        };
        let mut arguments = env::args().skip(1);
        while let Some(argument) = arguments.next() {
            match argument.as_str() {
                "--heartbeat" => config.heartbeat = next_path(&mut arguments, "--heartbeat")?,
                "--health-file" => config.health_file = next_path(&mut arguments, "--health-file")?,
                "--lock-file" => config.lock_file = next_path(&mut arguments, "--lock-file")?,
                "--stall-seconds" => {
                    config.stall_timeout =
                        Duration::from_secs(next_seconds(&mut arguments, "--stall-seconds", 30)?);
                }
                "--max-backoff-seconds" => {
                    config.max_backoff = Duration::from_secs(next_seconds(
                        &mut arguments,
                        "--max-backoff-seconds",
                        1,
                    )?);
                }
                "--check" => config.check = true,
                "--help" | "-h" => {
                    print_help();
                    std::process::exit(0);
                }
                _ => return Err(format!("unknown argument: {argument}")),
            }
        }
        if !config.heartbeat.is_file() {
            return Err(format!(
                "heartbeat executable does not exist: {}",
                config.heartbeat.display()
            ));
        }
        Ok(config)
    }
}

fn next_path(
    arguments: &mut impl Iterator<Item = String>,
    option: &str,
) -> Result<PathBuf, String> {
    arguments
        .next()
        .map(PathBuf::from)
        .ok_or_else(|| format!("{option} requires a path"))
}

fn next_seconds(
    arguments: &mut impl Iterator<Item = String>,
    option: &str,
    minimum: u64,
) -> Result<u64, String> {
    let raw = arguments
        .next()
        .ok_or_else(|| format!("{option} requires an integer"))?;
    let seconds = raw
        .parse::<u64>()
        .map_err(|_| format!("{option} requires an integer"))?;
    if seconds < minimum {
        return Err(format!("{option} must be at least {minimum}"));
    }
    Ok(seconds)
}

fn print_help() {
    println!(
        "navi-brain-supervisor [OPTIONS]\n\
         \n\
         --heartbeat PATH          heartbeat executable\n\
         --health-file PATH        metadata-only health JSON\n\
         --lock-file PATH          single-instance advisory lock\n\
         --stall-seconds N         restart after no heartbeat output (default 480)\n\
         --max-backoff-seconds N   restart backoff ceiling (default 60)\n\
         --check                   exit after one heartbeat record\n"
    );
}

struct InstanceLock {
    file: File,
}

impl InstanceLock {
    fn acquire(path: &Path) -> Result<Self, String> {
        ensure_private_parent(path)?;
        let mut file = OpenOptions::new()
            .create(true)
            .read(true)
            .write(true)
            .mode(0o600)
            .open(path)
            .map_err(|error| format!("cannot open lock file {}: {error}", path.display()))?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o600))
            .map_err(|error| format!("cannot harden lock file {}: {error}", path.display()))?;
        // SAFETY: flock is called with a valid, open file descriptor kept alive by this guard.
        if unsafe { flock(file.as_raw_fd(), LOCK_EX | LOCK_NB) } != 0 {
            return Err(format!(
                "another continuity supervisor holds {}",
                path.display()
            ));
        }
        file.set_len(0)
            .and_then(|_| writeln!(file, "{}", std::process::id()))
            .map_err(|error| format!("cannot write lock file {}: {error}", path.display()))?;
        Ok(Self { file })
    }
}

impl Drop for InstanceLock {
    fn drop(&mut self) {
        // SAFETY: the descriptor remains valid until this guard is dropped.
        unsafe {
            flock(self.file.as_raw_fd(), LOCK_UN);
        }
    }
}

#[derive(Debug)]
struct Health {
    state: String,
    child_pid: Option<u32>,
    restart_count: u64,
    consecutive_failures: u64,
    last_output_at: Option<u64>,
    last_heartbeat_ok: Option<bool>,
    last_error: Option<String>,
}

impl Health {
    fn new() -> Self {
        Self {
            state: "starting".to_owned(),
            child_pid: None,
            restart_count: 0,
            consecutive_failures: 0,
            last_output_at: None,
            last_heartbeat_ok: None,
            last_error: None,
        }
    }

    fn write(&self, path: &Path) -> Result<(), String> {
        ensure_private_parent(path)?;
        let file_name = path
            .file_name()
            .and_then(|name| name.to_str())
            .ok_or_else(|| format!("invalid health file path: {}", path.display()))?;
        let temporary = path.with_file_name(format!(".{file_name}.tmp.{}", std::process::id()));
        let payload = format!(
            "{{\"state\":\"{}\",\"supervisor_pid\":{},\"child_pid\":{},\"restart_count\":{},\"consecutive_failures\":{},\"last_output_at\":{},\"last_heartbeat_ok\":{},\"last_error\":{},\"updated_at\":{}}}\n",
            json_escape(&self.state),
            std::process::id(),
            optional_u64(self.child_pid.map(u64::from)),
            self.restart_count,
            self.consecutive_failures,
            optional_u64(self.last_output_at),
            optional_bool(self.last_heartbeat_ok),
            optional_string(self.last_error.as_deref()),
            epoch_seconds(),
        );
        let mut file = OpenOptions::new()
            .create(true)
            .truncate(true)
            .write(true)
            .mode(0o600)
            .open(&temporary)
            .map_err(|error| format!("cannot write health file {}: {error}", path.display()))?;
        file.write_all(payload.as_bytes())
            .and_then(|_| file.sync_all())
            .map_err(|error| format!("cannot flush health file {}: {error}", path.display()))?;
        fs::rename(&temporary, path)
            .map_err(|error| format!("cannot publish health file {}: {error}", path.display()))?;
        fs::set_permissions(path, fs::Permissions::from_mode(0o600))
            .map_err(|error| format!("cannot harden health file {}: {error}", path.display()))?;
        Ok(())
    }
}

enum Output {
    Stdout(String),
    Stderr(String),
    ReadError(String),
}

enum ChildOutcome {
    Exited(ExitStatus),
    Stalled,
    Stopped,
    CheckPassed,
}

fn main() {
    if let Err(error) = run() {
        eprintln!("navi-brain-supervisor: {error}");
        std::process::exit(1);
    }
}

fn run() -> Result<(), String> {
    let config = Config::parse()?;
    install_signal_handlers()?;
    let _lock = InstanceLock::acquire(&config.lock_file)?;
    let mut health = Health::new();
    health.write(&config.health_file)?;
    let mut backoff = Duration::from_secs(1);

    while RUNNING.load(Ordering::SeqCst) {
        health.state = "starting_child".to_owned();
        health.child_pid = None;
        health.write(&config.health_file)?;
        let outcome = supervise_child(&config, &mut health)?;
        match outcome {
            ChildOutcome::CheckPassed => {
                health.state = "check_passed".to_owned();
                health.child_pid = None;
                health.last_error = None;
                health.write(&config.health_file)?;
                return Ok(());
            }
            ChildOutcome::Stopped => break,
            ChildOutcome::Exited(status) => {
                health.last_error = Some(format!("heartbeat exited with {status}"));
            }
            ChildOutcome::Stalled => {
                health.last_error = Some(format!(
                    "heartbeat produced no output for {} seconds",
                    config.stall_timeout.as_secs()
                ));
            }
        }

        health.child_pid = None;
        if health.consecutive_failures == 0 {
            backoff = Duration::from_secs(1);
        }
        health.restart_count += 1;
        health.consecutive_failures += 1;
        health.state = "restart_backoff".to_owned();
        health.write(&config.health_file)?;
        if config.check {
            return Err(health
                .last_error
                .clone()
                .unwrap_or_else(|| "heartbeat check failed".to_owned()));
        }
        interruptible_sleep(backoff);
        backoff = (backoff * 2).min(config.max_backoff);
    }

    health.state = "stopped".to_owned();
    health.child_pid = None;
    health.write(&config.health_file)?;
    Ok(())
}

fn supervise_child(config: &Config, health: &mut Health) -> Result<ChildOutcome, String> {
    let mut command = Command::new(&config.heartbeat);
    command
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .process_group(0);
    let mut child = command
        .spawn()
        .map_err(|error| format!("cannot start {}: {error}", config.heartbeat.display()))?;
    let child_pid = child.id();
    let stdout = child
        .stdout
        .take()
        .ok_or_else(|| "heartbeat stdout was not piped".to_owned())?;
    let stderr = child
        .stderr
        .take()
        .ok_or_else(|| "heartbeat stderr was not piped".to_owned())?;
    let (sender, receiver) = mpsc::channel();
    let readers = vec![
        spawn_reader(stdout, sender.clone(), true),
        spawn_reader(stderr, sender, false),
    ];

    health.state = "running".to_owned();
    health.child_pid = Some(child_pid);
    health.write(&config.health_file)?;
    let mut last_output = Instant::now();

    loop {
        if !RUNNING.load(Ordering::SeqCst) {
            terminate_group(&mut child);
            join_readers(readers);
            return Ok(ChildOutcome::Stopped);
        }
        if let Some(status) = child
            .try_wait()
            .map_err(|error| format!("cannot inspect heartbeat process: {error}"))?
        {
            join_readers(readers);
            return Ok(ChildOutcome::Exited(status));
        }

        match receiver.recv_timeout(Duration::from_millis(200)) {
            Ok(Output::Stdout(line)) => {
                println!("{line}");
                io::stdout().flush().ok();
                last_output = Instant::now();
                health.last_output_at = Some(epoch_seconds());
                health.last_heartbeat_ok = heartbeat_ok(&line);
                if health.last_heartbeat_ok == Some(true) {
                    health.consecutive_failures = 0;
                    health.last_error = None;
                } else if health.last_heartbeat_ok == Some(false) {
                    health.last_error = Some("heartbeat record reported ok=false".to_owned());
                }
                health.write(&config.health_file)?;
                if config.check {
                    terminate_group(&mut child);
                    join_readers(readers);
                    return Ok(ChildOutcome::CheckPassed);
                }
            }
            Ok(Output::Stderr(line)) => eprintln!("heartbeat: {line}"),
            Ok(Output::ReadError(error)) => eprintln!("heartbeat pipe: {error}"),
            Err(RecvTimeoutError::Timeout) => {}
            Err(RecvTimeoutError::Disconnected) => {
                let status = child
                    .wait()
                    .map_err(|error| format!("cannot wait for heartbeat process: {error}"))?;
                join_readers(readers);
                return Ok(ChildOutcome::Exited(status));
            }
        }

        if last_output.elapsed() >= config.stall_timeout {
            terminate_group(&mut child);
            join_readers(readers);
            return Ok(ChildOutcome::Stalled);
        }
    }
}

fn spawn_reader<R: io::Read + Send + 'static>(
    input: R,
    sender: Sender<Output>,
    stdout: bool,
) -> JoinHandle<()> {
    thread::spawn(move || {
        for line in BufReader::new(input).lines() {
            let message = match line {
                Ok(line) if stdout => Output::Stdout(line),
                Ok(line) => Output::Stderr(line),
                Err(error) => Output::ReadError(error.to_string()),
            };
            if sender.send(message).is_err() {
                break;
            }
        }
    })
}

fn join_readers(readers: Vec<JoinHandle<()>>) {
    for reader in readers {
        let _ = reader.join();
    }
}

fn terminate_group(child: &mut Child) {
    let process_group = -(child.id() as i32);
    // SAFETY: the child was started in a new process group with its PID as PGID.
    unsafe {
        kill(process_group, SIGTERM);
    }
    let deadline = Instant::now() + Duration::from_secs(5);
    while Instant::now() < deadline {
        if matches!(child.try_wait(), Ok(Some(_))) {
            return;
        }
        thread::sleep(Duration::from_millis(50));
    }
    // SAFETY: the same process group is still owned by the supervised child.
    unsafe {
        kill(process_group, SIGKILL);
    }
    let _ = child.wait();
}

fn install_signal_handlers() -> Result<(), String> {
    // SAFETY: the handler only performs an async-signal-safe atomic store.
    let term = unsafe { signal(SIGTERM, stop_signal as *const () as usize) };
    // SAFETY: the handler only performs an async-signal-safe atomic store.
    let interrupt = unsafe { signal(SIGINT, stop_signal as *const () as usize) };
    if term == usize::MAX || interrupt == usize::MAX {
        return Err("cannot install signal handlers".to_owned());
    }
    Ok(())
}

fn interruptible_sleep(duration: Duration) {
    let deadline = Instant::now() + duration;
    while RUNNING.load(Ordering::SeqCst) && Instant::now() < deadline {
        thread::sleep(Duration::from_millis(200));
    }
}

fn ensure_private_parent(path: &Path) -> Result<(), String> {
    let parent = path
        .parent()
        .ok_or_else(|| format!("path has no parent: {}", path.display()))?;
    if !parent.is_dir() {
        fs::create_dir_all(parent).map_err(|error| {
            format!(
                "cannot create private directory {}: {error}",
                parent.display()
            )
        })?;
        fs::set_permissions(parent, fs::Permissions::from_mode(0o700))
            .map_err(|error| format!("cannot harden directory {}: {error}", parent.display()))?;
    }
    let mode = fs::metadata(parent)
        .map_err(|error| format!("cannot inspect directory {}: {error}", parent.display()))?
        .permissions()
        .mode();
    if mode & 0o077 != 0 {
        return Err(format!(
            "supervisor state directory must be private (0700): {}",
            parent.display()
        ));
    }
    Ok(())
}

fn heartbeat_ok(line: &str) -> Option<bool> {
    if line.contains("\"ok\":true") {
        Some(true)
    } else if line.contains("\"ok\":false") {
        Some(false)
    } else {
        None
    }
}

fn epoch_seconds() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

fn optional_u64(value: Option<u64>) -> String {
    value.map_or_else(|| "null".to_owned(), |value| value.to_string())
}

fn optional_bool(value: Option<bool>) -> &'static str {
    match value {
        Some(true) => "true",
        Some(false) => "false",
        None => "null",
    }
}

fn optional_string(value: Option<&str>) -> String {
    value.map_or_else(
        || "null".to_owned(),
        |value| format!("\"{}\"", json_escape(value)),
    )
}

fn json_escape(value: &str) -> String {
    let mut escaped = String::with_capacity(value.len());
    for character in value.chars() {
        match character {
            '"' => escaped.push_str("\\\""),
            '\\' => escaped.push_str("\\\\"),
            '\n' => escaped.push_str("\\n"),
            '\r' => escaped.push_str("\\r"),
            '\t' => escaped.push_str("\\t"),
            value if value.is_control() => {
                use std::fmt::Write as _;
                let _ = write!(escaped, "\\u{:04x}", value as u32);
            }
            value => escaped.push(value),
        }
    }
    escaped
}
