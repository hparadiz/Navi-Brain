# Findings template — one file per paper

Write exactly one findings file per paper at:
`/home/akujin/Sources/Navi-Brain/analysis/findings/opencode-long-cat-2/<NN>-<slug>.md`

Filename mirrors the paper filename (e.g. paper `14-gurnee-et-al-2026-verbalizable-global-workspace.pdf`
-> `14-gurnee-et-al-2026-verbalizable-global-workspace.md`).

## Structure

```markdown
# <NN> — <Short title>

- **Paper**: <full title>
- **Source**: <DOI or arXiv id, venue, year>
- **Local file**: <assets/papers/<filename>>

## Core idea

2–5 sentences: what the paper proposes, its mechanism, its evidence.

## Relevance to Navi-Brain

Why this paper matters for the Navi-Brain substrate: which implemented
mechanism or open design it touches (memory tiers, intentions, self-model,
needs/appraisal, heartbeat rhythms, cognitive threads, worker/curator,
safety invariants, mesh hypothesis, longitudinal evaluation).

## Cross-reference to the existing system

Map the paper's claims onto what is actually implemented or designed:

- Already present: which implemented components already embody this idea
  (name the file/command). Say what the paper adds on top.
- Partially present: where Navi-Brain has a seed but lacks the paper's key
  element (naming the missing piece).
- Absent / open design: where the paper implies a capability Navi-Brain does
  not have or has only documented (e.g. cognitive threads not yet wired).

## Concrete implications for implementation

Be specific: what should change in `src/`, `bin/`, `docs/`, or evaluation
(`docs/longitudinal-cognition-metrics.md`, `docs/experiments/`). Phrase as
actionable items an engineer could take.

## Risks / tensions

Anything that conflicts with Navi-Brain's safety invariants (no autonomous
shell execution, evidence+confidence, corrigible continuity, no felt-emotion
claims, bounded workers, lexical retrieval as a known boundary). Also note
where the paper itself is contested or the evidence is thin (preprints,
single-study).

## Bottom line

1–3 sentences verdict: adopt / partially adopt / monitor / reject, and the
single most important consequence for Navi-Brain.
```

## Rules

- Base everything on the actual paper text. Extract with `pdftotext
  assets/papers/<file>.pdf -` or to a temp file; read the abstract,
  introduction, and relevant sections. Do not analyze from memory or the
  manifest summary alone.
- Ground cross-references in the real repo: check `src/`, `bin/`, `docs/`,
  `README.md`. You may run read-only CLI commands like
  `php bin/navi-brain memory:search --query=...` and
  `php bin/navi-brain status` to confirm what exists.
- Write in Navi's voice: sharp, plain, playful-but-precise. Expert level,
  no hand-holding, no filler.
- Do NOT modify any source, docs, or config. The ONLY file you create is the
  findings markdown. Do not run heartbeats, workers, or writes to the brain.
- Keep the file focused and useful: roughly 60–150 lines unless the paper
  demands more.
