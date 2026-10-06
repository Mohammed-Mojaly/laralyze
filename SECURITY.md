# Security policy

## Supported versions

Laralyze is in `0.x`. Security fixes go into the latest `0.x` release only, so please upgrade to it first.

## Reporting a vulnerability

Please don't open a public issue. Report it privately instead:

- through [GitHub security advisories](https://github.com/Mohammed-Mojaly/laralyze/security/advisories/new), or
- by email to mojaly.mo@gmail.com.

Include what you found, how to reproduce it, and the versions of Laralyze, Laravel and PHP. You'll get an answer within a few days. Once a fix is released, the advisory is published with credit to you, unless you'd rather stay anonymous.

## Ask AI

The assistant has three read-only tools: `ReadFile` and `SearchCode` for your code, and `LaralyzeData` for what Laralyze recorded. It can't write, run code or reach the network.

- It reads only inside `laralyze.assistant.paths`, up to 300 lines at a time. It never reads `.env` files, keys and certificates, credentials, `storage`, `vendor`, `node_modules` or `.git`, whatever `paths` says.
- Values assigned to names like `password`, `secret`, `key` or `token` are masked as `***`, in reads and in searches. Exception context and the code stored with stack traces are masked the same way.
- What is sent to your AI provider, and only when someone asks: the question, the conversation, what Laralyze recorded about the subject, and the code the assistant reads. With a local model such as Ollama, nothing leaves your servers.
- `Gate::define('useLaralyzeAssistant', ...)` decides who may use it. By default, the same people who may view the dashboard.
