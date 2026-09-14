# Intention terminals

An explicitly selected canonical intention can run in a detached GNU Screen
terminal using OpenCode and `opencode/muse-spark-1.3-contributor-free`. This uses
the installed Screen and OpenCode binaries, plus PHP's pcntl and posix extensions.
It adds no database migration or background scheduling service.

```sh
php bin/navi-brain intention:list --status=active
php bin/navi-brain intention:agent:context --id=123
php bin/navi-brain intention:agent:start --id=123 --workspace=/absolute/workspace
php bin/navi-brain intention:agent:status
php bin/navi-brain intention:agent:attach --id=123
# Ctrl-a d detaches while the agent continues.
php bin/navi-brain intention:agent:stop --id=123
# The same start command resumes the conversation with fresh intention context.
```

Replace `123` with the selected intention ID. Context preview is local and prints
the full supplied context. Starting the terminal submits that context and any
subsequently read workspace material to OpenCode's selected external model.
It includes the accepted personality and only the assigned intention's canonical
contract, dependency state, and action/decision evidence. The aggregate background
intention narrative and unrelated memories are excluded. The context identifies
stored authority labels as provenance, not new permission.

Each database/intention pair owns one terminal, a private conversation database,
and a context file under `var/intention-agents/` (ignored by Git). Starting an
already running intention returns its existing session. Start/stop commands are
serialized with a file lock, and a second runner cannot own the same intention.
The original workspace is retained on resume. There is no retry loop, boot-time
restart, automatic launch of remembered intentions, or automatic claim of
completion. Progress stays in the conversation for review; canonical intention
updates still use the existing intention commands.

The primary and auxiliary models are pinned to the selected free Muse model;
OpenCode's provider/model allowlist excludes paid alternatives. The dedicated
agent has an eight-step turn limit. It may read/search, while other tools use
interactive permission prompts. Attach to answer those prompts. Project OpenCode
configuration and external plugins are disabled; workspace instruction files
still apply. These are OpenCode permissions, not an OS filesystem sandbox.
Ordinary local operations stay unprivileged, and root operations must follow the
workspace's elevation policy. `OPENCODE_BIN` can select the installed CLI path.

These interactive sessions are separate from the sparse consolidation worker:
they do not use its 90-minute quota gate. They submit one startup/resume prompt,
then remain available for interaction. The step limit is not a guarantee about
HTTP retries or total requests across manually submitted turns. The existing
consolidation privacy setting is unchanged.

A local watchdog checks every two seconds. It stops the terminal if cognition is
paused, its intention becomes inactive, a dependency is unfinished, its parent
is inactive, the intention contract changes, or canonical state cannot be read.
Stopping cannot undo effects that already occurred. No closed or blocked
intention is automatically resumed. Screen closes the terminal on exit; ordinary
child processes receive its hangup. Processes deliberately detached by a tool
are outside this terminal's lifecycle.

Screen sockets use a private `/tmp/navi-tty-<uid>-<database-hash>` directory to
avoid depending on the workstation's global Screen socket setup. Status includes
the exact attach command with `SCREENDIR`. A real terminal type, such as
`xterm-256color`, is needed for interactive attach. A sandbox which denies Unix
sockets or PTY creation also prevents Screen from starting; a successful Screen
fork alone is not reported as a successful child launch.

## Validation

Verified with a disposable executive database and a local dummy executable:
scoped context, real TTYs on all three streams, detached startup, duplicate-start
reuse, attach/detach, stop and child cleanup, assignment-change and pause shutdown,
dependency/blocked admission rejection, and reuse of the same database with the
resume flag. The installed OpenCode accepted the generated configuration with
the pinned models, scoped instructions, and permission settings. This validation
made no inference calls and did not submit live memory to a provider. Actual
model conversation resumption has not been exercised by this change.

OpenCode's [CLI reference](https://opencode.ai/docs/cli/) documents the TUI's
`--continue`, `--session`, `--prompt`, `--model`, and `--agent` options.
