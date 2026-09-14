# GPT-6 Astra corpus review protocol

Aku requested one dedicated GPT-6 Astra analyst per paper, with a shared implementation master supplying current code evidence. This review includes the 84 catalogued PDFs, the two inbox PDFs, and the current Continuous State, Bounded Computation working paper. Earlier drafts of that working paper are provenance, not additional papers.

The built-in collaboration harness retains completed threads and reached its four-thread limit after the root, implementation master, and two paper analysts. Papers 14 and 31 therefore use native collaboration subagents; the remaining papers use fresh GPT-6 Astra `codex exec --thread-source subagent` sessions, at most two concurrently. Each session still owns exactly one paper. The implementation master remains the sole code researcher. CLI analysts send bounded questions through their per-paper temporary job directory; the root obtains and relays the master's answers. The manifest records actual thread provenance rather than inventing native agent identifiers. Two early CLI jobs (01 and 23) used ephemeral sessions: their exact model request and successful completion are retained in launch/event records, while persisted runtime model metadata is unavailable. The other analyst sessions permit checking the model in saved turn metadata. Final validation distinguishes these evidence types.

## Analyst workflow

1. Read your assigned local paper itself. Extracted, page-separated text is available in `/tmp/navi-astra-paper-text/<PDF-stem>.txt`; the PDF is authoritative. Read the substantive argument, methods, results, limitations, and relevant appendices. For long papers, state the extent of appendix coverage. Inspect equations or figures in the PDF if text extraction loses essential information. Cite PDF page numbers and section/table/figure identifiers, distinguishing PDF pages from printed page numbers when needed.
2. Read `IMPLEMENTATION.md` in this directory. The implementation master owns code research. Do not independently survey `src/`, `bin/`, configs, tests, README, or previous findings. Ask `/root/implementation_master` bounded implementation questions using collaboration messages. If it is idle, send the question to `/root` for reactivation. Read the shared reference again before finalizing, since answers may have been added.
3. Write exactly your assigned findings file. Do not edit implementation, existing literature reviews, corpus manifests, configuration, tests, or canonical memory. Do not invoke brain/heartbeat/worker mutations. This task produces review documents, not the proposed changes.
4. Send `/root` the output path, your central finding, and unresolved evidence limitations. Keep worker communication in collaboration messages; the root handles user-facing updates and voice.

## Required content

Use the existing numbered-paper/slug filename convention for catalogued papers. Begin with a level-one heading containing the paper's actual title, followed by authors/year/version/venue, local source link, reviewer model, and implementation reference date/revision. Link the shared implementation reference and cite its section identifiers alongside code paths and symbols it verified.

- **Core idea and mechanism:** Explain the actual computational or theoretical contribution, including the assumptions that make it work. Separate the paper's claims from your interpretation.
- **Evidence and limits:** Identify experiments, comparisons, significant results, and the limits of inference. Architecture proposals, proofs under assumptions, empirical results, surveys, and positions have different evidential status. Do not manufacture numerical results or treat association as intervention.
- **Relevance to Navi-Brain:** Explain what transfers, at which architectural level, and what does not. A functional analogy is not algorithmic equivalence or proof of consciousness.
- **Implementation cross-reference:** Distinguish already implemented, partially implemented, absent after a stated search, and not verified. Ground claims in the implementation master's code evidence. Do not mistake records or names for working mechanisms, and do not repeat obsolete claims from older reviews.
- **Recommendations:** Give a small number of prioritized, paper-specific suggestions. Each needs a concrete mechanism/change location, the reason it follows from this paper, dependencies or cost, and an observable validation/ablation with a failure criterion. It is valid to recommend no production change. Label speculative synthesis explicitly.
- **Risks and disagreements:** Include conflicts with other interpretations, missing evidence, costs, or limits that materially affect adoption. Keep this specific to the paper rather than repeating a generic checklist.
- **Verdict:** A clear adopt/experiment/monitor/reject judgment, with the most consequential next step.

Aim for a useful, technically substantial review, usually 1,200–2,200 words; shorter where the source is short and longer for dissertations or unusually broad papers. Precision and independent judgment matter more than length. Use the paper's primary contents rather than a web summary. Browse primary sources only when needed to resolve missing or unstable external facts, and record which version supports each claim. A fresh publication-status search is unnecessary when analyzing an identified local version.

## Provenance and verification

The root maintains `INDEX.md` and a machine-readable `review-manifest.json` identifying every source, source digest, analyst task name, output, and status. Source links should be relative to this directory (`../../../assets/...`); code references may use the same repository-relative traversal plus symbols. Do not present proposed experiments as performed or static inspection as live runtime validation.
