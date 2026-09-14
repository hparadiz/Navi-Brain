# Groq synthetic pilot

The optional `bin/navi-brain-groq-pilot` command prepares one fictional memory-update request. It is separate from the executive, workers, database bootstrap, memory store, and service configuration. No production routing is enabled.

Run `php bin/navi-brain-groq-pilot` to inspect the exact request. This default mode does not inspect the environment or credentials and does not open a network connection. The command accepts no prompt, file, stdin, endpoint, model, or schema overrides.

An operator with explicitly authorized **no-charge account access** can supply `GROQ_API_KEY` through the process environment and run:

```sh
php bin/navi-brain-groq-pilot --send-synthetic --account-no-charge-confirmed
```

The confirmation flag is an operator assertion, not an account billing check. The client cannot determine whether an account will charge for a call. Do not use that flag without verifying the account's available free allowance and billing controls. Account creation, private cognition egress, paid usage, and production integration require separate decisions. See the [provider research](cognition-inference-providers-2026-09.md).

## Request and response contract

- One direct request to `https://api.groq.com/openai/v1/chat/completions`, pinned to `openai/gpt-oss-120b`, with no retries or fallback. It sends no tools and never executes a response.
- Uses `LocalModelWorker::proposalSchema()` for the existing four-field proposal shape, constraining `kind` to the synthetic operation. The transport does not yet support the eight-field consolidation proposal.
- Maximum 4,096 encoded request bytes; this is a byte bound, not a tokenization measurement. The fixed example is small. The model is asked for at most 512 completion tokens at low reasoning effort; hidden reasoning still consumes the completion budget. The client rejects a response reporting completion or reasoning usage above that cap. A cap violation detected after a request cannot undo billed generation.
- Requires PHP ext-curl with asynchronous DNS. The transport sets a 5-second connect deadline and a 20-second total request deadline, verifies the certificate and hostname, permits HTTPS only, disables redirects and inherited proxies, and ignores netrc credentials.
- Caps response bodies at 65,536 bytes, headers at 16,384 bytes, and proposal JSON at 8,192 bytes. Refusal, tool calls, truncation, unexpected model/role, malformed JSON, or an invalid four-field proposal are rejected.
- Exposes only fixed error codes and numeric HTTP status, latency, and parsed `Retry-After` seconds. It never logs provider error bodies, raw headers, credentials, reasoning text, or exception stacks. A 429 is reported without retrying or sleeping.
- Usage fields are provider-reported observations. Missing fields remain `null`; they are not counted as zero. The requested completion cap includes reasoning, so reasoning tokens must not be added a second time to reported completion tokens when calculating cost.

The JSON output includes request-to-result latency and an exact content match for a deliberately simple instruction. That match is only a transport/instruction screen: it does not demonstrate semantic correction, general reasoning quality, recall improvement, or production p95. Inspect the proposal's challenged assumption separately. No provider has passed a measured pilot merely because this command exists.

Transport and reasoning settings follow the current [Groq API reference](https://console.groq.com/docs/api-reference), [structured output documentation](https://console.groq.com/docs/structured-outputs), and [reasoning documentation](https://console.groq.com/docs/reasoning), consulted 2026-09-06. Schema-subset acceptance and actual token usage still require an authorized request; the client does not weaken the schema on rejection.

The initial implementation was checked with PHP syntax validation and static review only. No credentials were read, network inference requests submitted, service changes made, or behavioral tests added as part of implementation.
