# Dead-code and bin usage audit

Audited 2026-09-14 against `c5a2959`, including repository entry points and this
workstation's installed skills, hooks, MCP configuration, plugins, and OpenRC
services. Absence from automatic callers alone does not make a documented manual
command dead.

## Removed

The initial dead-code pass removed 548 source/script lines. The subsequent
refactor replaced `ExecutiveCore.php` with concrete classes under
`src/Core/ExecutiveCore/`. All application PHP files are now below 1,000 lines.
The C memory backend remains active; migration and recovery tools remain
available for existing durable state. No runtime speedup is claimed.

| Component | Removed methods or leftovers | Evidence |
| --- | --- | --- |
| `ExecutiveCore` | `markActionExecutionWaiting`, `suspendProcedureRunForAction`, `intendLook`, `contextStatus`, `addressedLine`, `lastSpokenAt` | No executable callers; current async completion, curiosity, context transport, and speech paths use other methods. |
| `DecisionStateMachine` | `finishVerified` | Unreachable earlier completion implementation; current completion uses the executive's async finalization. |
| `ExecutiveComposition` | `functions` | Unused accessor; contribution and prompt assembly remain reachable. |
| `ProceduralMemory` | `planningAdapters` | Unused accessor; adapter listing and dispatch remain reachable. |
| `HearingStream` | `secondsSinceSpeech` | Unused accessor; active speech annotation and silence handling remain. |
| `SignalSampler` | `audioCapture`, `heardSpeechAge`, private `run` | Capture probe was explicitly unwired; no callers for either public helper; runner was used only by the removed probe. |
| `Memory` | SQLite index definitions | Memory is excluded from SQL schema creation; persistence uses `TokenMemoryDaemon`. ActiveRecord hydration and field metadata remain in use. |
| Miscellaneous | Two unused imports and two write-only locals | No remaining references. |
| `bin/mesh-replicate-loop` | Entire script | No repository or inspected installed caller. Manual native replication remains available through `bin/replicate`. |
| `bin/mic_transcript_watch.php` | Entire script | Superseded by the installed plugin's `mic_push.php` path; no invoking caller found. |

## Bin inventory

Fifteen of the original twenty files have concrete configured or internal
callers. The remaining five are the two removed scripts and three retained
manual tools.

| Original bin file | Use found | Disposition |
| --- | --- | --- |
| `claude_turn_memory.php` | `.claude/settings.json`: SessionStart, Stop, SessionEnd | Keep |
| `codex_turn_memory.php` | `~/.codex/config.toml`: notify and session hooks | Keep |
| `hearing_context.php` | Installed OpenCode input plugin and transcript skills | Keep |
| `mic_push.php` | `~/.config/opencode/plugin/navi-input.ts` | Keep |
| `mic_transcript_cursor.php` | Installed OpenCode and Claude transcript skills | Keep |
| `opencode_thread_memory.php` | Installed OpenCode thread-memory skill | Keep |
| `navi-brain` | Installed CLI alias, session-memory skills, documented commands | Keep |
| `navi-brain-mcp` | `.mcp.json`, installed Codex/OpenCode/Claude MCP configuration | Keep |
| `navi-brain-heartbeat` | Continuity supervisor and OpenRC service | Keep |
| `navi-brain-intention-agent` | `IntentionTtyAgent::start` | Keep |
| `navi-brain-local-model` | OpenRC service and Makefile | Keep |
| `navi-brain-model-worker` | Installed OpenRC worker services | Keep |
| `navi-brain-senses` | Installed OpenRC service | Keep |
| `navi-senses` | OpenRC service, installer, `CommandSense` socket client | Keep |
| `token-memory-bundle` | `ExecutiveCore::backupDatabase` and `bin/replicate` | Keep |
| `replicate` | Manual native-store replication documented in `token-memory-cutover.md` | Keep: supported manual operation |
| `token-memory-legacy-fence` | Documented migration fence install/status/remove commands | Keep: cutover/rollback tooling, not a runtime storage backend |
| `navi-brain-groq-pilot` | Documented isolated experiment in `groq-synthetic-pilot.md` | Keep: manual experiment; sole consumer of `GroqModelClient` |
| `mic_transcript_watch.php` | Installed alias only; no invoking caller found | Remove superseded script |
| `mesh-replicate-loop` | No caller found | Remove orphan loop |

The installed `~/.config/opencode/scripts/mic_transcript_watch.php` symlink still
targets the main checkout. It is an obsolete alias and should be removed when
deploying this branch; this source cleanup does not change workstation config.
User crontab contents could not be inspected, so the audit does not establish
absence of cron callers. Conversation archives and credentials were excluded.

## C memory boundary and validation

Normal recall/status and MCP memory transport use the native daemon. Application
memory reads and writes also go through `TokenMemoryDaemon`; no direct legacy
`memories` SQL table operations were found in PHP application code. The remaining
PHP search reranking and executive orchestration have live callers. Historical
schema migrations and old-record recovery paths remain necessary for durable
state compatibility. The legacy fence tool intentionally inspects the old table
for explicit migration operations.

Validation: all 194 PHP files lint. Token and AST checks find no traits,
`final`, ordinary PHP comments, transaction-remnant closures, or methods with
more than five parameters. The largest PHP file is 832 lines. The initial
shell/Python entry-point checks passed; CLI and model-worker help still pass.
The C core builds with `-Wall -Wextra -Wpedantic`.

Isolated SQLite/native integration covers fresh schema migrations, ordinary
model saves, memory replay and replacement, journals, capsules, working-memory
maintenance, and queue claim/completion. Temporary daemons were stopped. No live
database or services were changed. `git diff --check` passes. No tests or test
infrastructure were added.
