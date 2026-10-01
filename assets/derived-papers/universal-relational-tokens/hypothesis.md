# Universal relational tokens as a testable hypothesis

Working draft 0.2 · 1 October 2026

Originating hypothesis: Aku. Developed into this test brief with Navi.

Status: Proposed. No experiments have been run for this brief.

## Core hypothesis

Auditory, visual, linguistic, and other information can be represented through the same basic units and relational operations. A unit has no privileged meaning by itself. Its connections, learned history, and role in perception and action determine what it represents. Learning changes this structure; attention selectively activates parts of it for a decision.

Encoding is automatic in this proposal. Incoming signals do not need an existing conscious concept or a conscious instruction to be encoded. Conscious cognition can call up, reuse, and connect the resulting tokens without choosing their elementary encoding. Universal refers to the reach of that underlying process: it can represent any experience that the substrate can tokenize, including unfamiliar sensory input.

The stronger biological proposal is that the brain uses a common underlying encoding and storage scheme across modalities, with specialization arising through inputs, connectivity, and learned organization. We retain that as a hypothesis to investigate. Building a useful software implementation would establish engineering feasibility, while the biological claim requires neural evidence.

Adding a perceptual dimension need not add raw computational throughput. An entity may distinguish more kinds of input while retaining the same finite processing budget. Whether reliable information bandwidth also stays constant must be measured independently.

The practical question for Navi is whether a shared relational representation improves learning, transfer, and decisions within a finite attention and computation budget.

## What the terms mean

**Token:** A reusable representational unit. It could be implemented as a symbol, vector, or distributed pattern. The proposal does not require one token to equal one neuron or one language-model token.

**Universal:** The same representation primitives, composition rules, and learning operations can serve any tokenizable input after sensory transduction, without conscious selection of its encoding. Merely storing audio and images in the same database does not establish this claim. A test must specify these shared rules before observing the result.

**Automatic encoding:** Representation occurs without a conscious instruction to perform it. Initial encoding, durable storage, and later retrieval are distinct outcomes; automatic encoding does not imply that every detail is retained indefinitely.

**Relation:** A learned connection that can express identity, similarity, sequence, part membership, context, or a predictive dependency. Relations may retain strength, timing, provenance, and uncertainty.

**Common encoding:** Shared rules for forming and updating representations. Different experiences can still produce different patterns. Consistently renaming internal identifiers while preserving their relations and processing should preserve behavior; this is a design invariant, not evidence of biological universality.

**Attention in a decision interval:** The task-relevant information that demonstrably influences a decision before its deadline. We measure its consequences through controlled tasks and interventions, rather than assuming a universal count of things a mind can attend to.

## New sensory input and the control boundary

The third-eye thought experiment asks what happens when functioning circuitry receives an unfamiliar sensory channel. The proposal predicts that it enters the encoding process even before the organism knows what it means. Experiments must distinguish a neural response from a stable, distinguishable representation that can be reused and connected to other knowledge. Plasticity may affect this transition; unfamiliar input causing brain damage is a separate speculation requiring a mechanism and evidence.

The proposed boundary concerns direct control over elementary encoding. It permits indirect feedback: attention, action, and learning can alter which signals arrive and how representations are reinforced. Attentional modulation of sensory responses therefore needs to be measured rather than excluded by definition. Automatic encoding and common encoding remain separate claims: specialized automatic encoders connected through a shared semantic system are a competing explanation.

## The yellow example

Seeing a yellow surface, hearing someone say “yellow,” and reading its letters are different inputs. Learning can connect them while preserving their differences. Red and blue renderings of the letter “y” can share a letter identity. The ordered composition “yellow” can activate an associated color category even when the word is printed in blue. A new speaker can be recognized through learned similarities to earlier speech.

The proposed mechanism has to explain both invariance and discrimination: recognizing a word despite changes in ink color, while retaining ink color when that is what the task asks about. It must also generalize to unfamiliar examples. A lookup table containing every training example would be an inadequate demonstration.

## Predictions and measurements

| Claim | Measurement and comparison | Result that would weaken it |
| --- | --- | --- |
| Unfamiliar input can enter the common encoding process automatically | Introduce a held-out input channel before teaching its meaning; measure repeatable discrimination, retention, and later reuse under predefined shared encoding rules. | The implementation requires a new specialized encoder, or its apparent representations do not support discrimination and reuse under the specified conditions. |
| Shared representations support transfer across modalities | Teach a relation through images, then test its use through audio. Compare shared and separate representations with matched resources and training exposure. | A well-powered comparison excludes a practically useful advantage, or apparent transfer disappears when familiar examples are withheld. |
| Learned connections carry functional content | Perturb selected associations and measure errors on the relations they support, alongside unaffected control questions. | Perturbations have no selective effect, suggesting decisions bypass the proposed relational mechanism. |
| Learning reusable abstractions improves effective attention | At fixed hardware and deadlines, measure how many independently varied relevant facts are correctly used in held-out decisions before and after a controlled curriculum. | Gains occur only on practiced items, depend on extra resources, or vanish on new compositions. |
| Embodiment constrains decisions through specific bottlenecks | Vary sensory bandwidth, compute, memory access, and communication delay separately; measure accuracy and deadline failures. | The proposed bottleneck fails to predict observed scaling after alternative explanations and measurement errors are checked. |
| More perceptual dimensions can compete within a fixed processing budget | Increase independently varying, task-relevant features while holding reliable information capacity, compute, and deadlines fixed; predict the resulting precision and update-rate tradeoff. | The predicted tradeoff fails after accounting for spare capacity, correlations, coding efficiency, and changes in strategy. |

Each row is a separate claim. Success on one does not validate the others. A shared representation may be feasible without outperforming a competent modular system.

## First feasible experiment

Start with a small transfer task. Its purpose is to test the causal usefulness of shared relational memory; it cannot settle whether biological brains use an identical code across modalities.

**Task.** Generate independent small worlds containing 24 objects with randomized spoken and written nonsense names. Present their visual forms using varied colors, shapes, fonts, and viewpoints. Pair names and objects during familiarization. Teach new, arbitrary relations between objects through one modality, then query those relations through another. For example, teach visually that object A unlocks object B, then ask about the relation using only their spoken names. Test unfamiliar speakers, renderings, and combinations separately.

Include a correction phase: change one learned relation through a single modality, then test whether that correction transfers to unfamiliar examples in the other modality while unrelated knowledge remains intact. All conditions receive the same correction evidence. Measure the observations and update work required, not just eventual accuracy.

**Conditions.** Compare:

1. A shared relational memory with learned associations and reusable compositions.
2. Separate modality memories with a competent learned association bridge and the same total resource budget.
3. The shared memory with selected cross-modal association endpoints shuffled among unrelated objects, while preserving the amount of stored material.

Use the same frozen sensory frontends and generative core for this first study. That isolates memory organization. Keep exposure, available evidence, total storage, and processing budgets matched. A later study must compare genuinely shared versus separately learned encoding and update rules. Merely putting equivalent graphs in different tables is not a meaningful architectural comparison.

**Controls.** Randomize label meanings for every world. Split speakers, renderings, and relational compositions before training. Keep evaluator labels and answer keys out of model inputs. Give the modular baseline access to the same paired examples. Include questions that depend only on one modality, so general damage can be distinguished from a specific loss of integration. Use fresh experimental state for each world.

**Primary outcome.** Accuracy on held-out questions requiring transfer of a newly learned relation across modalities. Report the paired difference between conditions across independent worlds.

**Secondary outcomes.** Correction transfer, confidence calibration, accuracy on unaffected questions, observations needed to reach a fixed accuracy, update work, memory use, processing cost, median and 95th-percentile latency, and missed deadlines. Record decisions, confidence, evidence references, and outcomes; hidden reasoning traces are unnecessary.

**Decision rule.** A provisional engineering target is an improvement of at least five percentage points in the primary outcome at matched budgets. Use a small pilot to estimate variance, check shortcuts, and choose a confirmatory sample size. Freeze the sample size, scoring, exclusions, and threshold before the confirmatory run. A paired 95% confidence interval entirely above the target would support that practical advantage; an interval entirely below it would count against it. An interval spanning the target is inconclusive. The five-point threshold is a proposed usefulness criterion, not a biological constant.

## Follow-up tests when practical

**Shared encoding.** Train shared and modality-specific encoders under matched data and parameter budgets. Constrain how much representation learning can be hidden in separate frontends. Test novel combinations and intervene on candidate shared representations. Measure whether a targeted change has corresponding effects across modalities while unrelated concepts remain intact. Compare against explicit association models, which can also produce cross-modal transfer.

**Automatic formation and retained detail.** Introduce unfamiliar signals before supplying labels or relational instructions. Measure discrimination, retention, and subsequent learning separately. In another condition, reveal whether the task concerns word identity, ink color, or speaker only after the stimulus disappears. This tests what survives abstraction and what retention costs. Unlabelled exposure alone does not establish absence of conscious involvement; the biological control-boundary claim needs additional evidence.

**Learning and pretraining.** Vary exposure diversity while holding its quantity fixed: more speakers, accents, fonts, and viewpoints versus repeated familiar examples. Separately vary exposure quantity. Test sample efficiency and unfamiliar cases. This distinguishes diversity, scale, and architecture. Evaluate mathematical or other abstraction curricula through transfer to new tasks; early mathematical competence is not assumed to cause general adult understanding.

**Bounded attention.** Fix a decision deadline and independently vary input bandwidth, computation, and communication delay. For a channel capped at B bits per second and a processor capped at R defined operations per second, a duration dt admits at most B × dt new channel bits and R × dt operations. These are resource accounting bounds, not direct measures of intelligence. Existing memory and learned compression can increase useful decisions per unit of work. Measure the resulting performance frontier rather than proposing one universal intelligence ceiling.

**Dimensions versus bandwidth.** Distinguish the number of independent perceptual features, reliable information bits per second, and raw processing operations per second. More distinguishable states at the same sample rate can convey more information, so unchanged physical connections do not establish unchanged information bandwidth. In a simplified digital channel, n independent features supplying b fresh information bits each at f updates per second require n × b × f ≤ C, where C is reliable channel capacity. At saturation, increasing n requires a tradeoff in precision or update rate. Real inputs may contain exploitable redundancy; spare capacity and improved coding must be checked before interpreting a missing tradeoff. Better distinctions can improve task performance even at unchanged raw compute.

**Biological evidence.** Look for shared coding and learning rules across sensory areas and for causal interventions affecting equivalent representations across modalities. A neuron responding to both pictures and names establishes convergence at that level; it does not establish a universal storage code for all sensory detail. Persistent associations and their activation on demand must also be distinguished.

## Evidence that motivates the tests

- Rewiring retinal input into the auditory pathway of developing ferrets produced visual orientation-selective organization in auditory cortex. Its organization was not identical to ordinary visual cortex. This supports adaptable cortical machinery. [Sharma, Angelucci and Sur, 2000](https://www.nature.com/articles/35009043)
- Some human medial temporal lobe neurons responded to pictures and to the written and spoken names of the same individual. This supports representations shared across sensory inputs. [Quian Quiroga et al., 2009](https://authors.library.caltech.edu/records/8kbbk-b9p58)
- Adult monkeys acquired trichromatic discrimination after addition of a third cone photopigment. This supports adaptation to new sensory distinctions beyond early development; it does not establish unchanged total bandwidth or compute. [Mancuso et al., 2009](https://www.nature.com/articles/nature08401)
- Adult rats learned to use an infrared sensor coupled to somatosensory cortex. The study could not determine whether this produced a new subjective modality or an association with tactile sensation. [Thomson et al., 2013](https://www.nature.com/articles/ncomms2497)
- Attention modulated visual neuron responses, motivating a distinction between direct choice of an encoding and feedback influencing sensory processing. [McAdams and Maunsell, 1999](https://pubmed.ncbi.nlm.nih.gov/9870971/)
- Models connecting modality-specific representations through a shared semantic system provide an alternative explanation for cross-modal behavior. Shared embedding systems such as ImageBind also provide relevant engineering comparisons. [Rogers et al., 2004](https://web.stanford.edu/~jlmcc/papers/RogersETAL04.pdf); [Girdhar et al., 2023](https://openaccess.thecvf.com/content/CVPR2023/html/Girdhar_ImageBind_One_Embedding_Space_To_Bind_Them_All_CVPR_2023_paper.html)
- Google's Universal Speech Model used encoder pretraining on 12 million hours of audio across more than 300 languages. This demonstrates the scale of perceptual training available to artificial systems; it does not demonstrate a universal biological tokenizer. [Zhang et al., 2023](https://arxiv.org/abs/2303.01037v3)
- Information content and channel capacity distinguish richer signals from faster sampling or additional physical connections. [Shannon, 1948](https://people.math.harvard.edu/~ctm/home/text/others/shannon/entropy/entropy.pdf)
- Physical computation is constrained by its implementation and resources. This motivates measuring finite decision budgets. [Lloyd, 2000](https://arxiv.org/abs/quant-ph/9908043)
- The consciousness hierarchy paper motivated this discussion through its treatment of computational organization and embodiment. It supplies context, not a validation of this hypothesis. [Chandaria et al., 2026, sections 5 and 8](https://arxiv.org/abs/2609.35618v2)

## Next step and interpretation

The main unresolved objection is identifiability: successful transfer, including correction transfer, can arise from either a shared encoding or competent translation between specialized encodings. Frozen frontends can also supply the abstractions being credited to memory. The first pilot therefore tests a useful memory design; it cannot by itself validate universal encoding. The representation rules, alternative models, and expected differences must be specified for the stronger test.

Before implementation, select an accessible sensory pipeline and inspect the existing [Navi cognition experiment program](../../../docs/experiments/README.md). This brief does not change that program's execution order. A pilot using transcripts or extracted features must be labeled as a memory and transfer test; testing perceptual encoding itself requires access to the relevant audio and visual processing.

The next useful result is a reproducible comparison showing whether a particular shared representation changes decisions on unfamiliar inputs. Software success would support that implementation. Repeated failure would justify revising its assumptions or abandoning its claimed advantage. Neither outcome alone establishes the biological universality claim or subjective consciousness.
