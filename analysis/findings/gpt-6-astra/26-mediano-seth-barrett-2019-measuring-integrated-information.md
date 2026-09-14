# Measuring Integrated Information: Comparison of Candidate Measures in Theory and Simulation

**Authors:** Pedro A. M. Mediano, Anil K. Seth, Adam B. Barrett.  
**Version/venue:** Local PDF is **arXiv:1806.09373v1, 25 June 2018**, an arXiv preprint. The repository filename says 2019; this review evaluates the actual 2018 version and does not assume later corrections.  
**Source:** [Local PDF](../../../assets/papers/26-mediano-seth-barrett-2019-measuring-integrated-information.pdf).  
**Reviewer:** GPT-6 Astra.  
**Implementation evidence:** [Shared implementation reference](IMPLEMENTATION.md), snapshot 2026-09-06, git HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`; static findings supplied by the implementation master.

## Core contribution and mechanism

The paper establishes that “integrated information” is an underspecified measurement objective: plausible formalizations disagree even on small, exactly tractable systems. Its useful transfer to Navi-Brain is a method for challenging integration metrics, rather than a reason to maximize one. The authors favor integrated synergy ψ, decoder-based Φ*, and causal density (CD), but an analytical counterexample below materially weakens adoption of their particular Gaussian ψ.

All six measures concern the **spontaneous joint distribution** of past and present states, $p(X_{t-\tau},X_t)$, under stationarity. They do not implement the intervention-based, maximum-entropy construction of IIT 2/3; IIT 3.0 is explicitly outside scope (§§3.1, 3.9, 5.3; pp. 5, 15–16, 27). A partition divides the observed variables into disjoint parts. Effective information evaluates what is lost across that partition; partition selection is a separate operation.

| Measure | Mechanism and interpretation | Principal qualification |
|---|---|---|
| Whole-minus-sum Φ | Whole-system time-delayed mutual information (TDMI), minus the sum of each part's own TDMI (§3.3, pp. 7–8). | Can be negative; redundancy can overwhelm synergy. Negative values are not negative consciousness. |
| Stochastic interaction Φ̃ | Sum of within-part conditional past entropies minus whole-system conditional past entropy (§3.4, pp. 9–10). | Equals whole-minus-sum effective information plus instantaneous total correlation, so shared noise can dominate. |
| Integrated synergy ψ | Whole TDMI minus information available without synergy, using a partial information decomposition (§3.5, pp. 10–12). | Depends on the redundancy definition. The Gaussian calculation extends minimum-mutual-information PID to a multivariate target. |
| Decoder-based Φ* | TDMI minus information recoverable by a mismatched decoder whose conditional model factorizes across parts (§3.6, pp. 12–13). | Requires optimizing a scalar decoder parameter; arbitrary continuous distributions lack the supplied Gaussian formula. |
| Geometric ΦG | Minimum KL divergence to a model forbidding cross-part predictive connections while permitting residual correlations (§3.7, pp. 13–14). | Requires constrained numerical optimization and need not track shared-noise changes. |
| CD | Mean directed conditional transfer entropy, conditioning on the target's past and remaining parts (§3.8, pp. 14–15). | Predictive dependence under an observation model; the name does not establish interventionally identified causation. |

The authors standardize their comparisons by minimizing unnormalized effective information over **equal-sized bipartitions** (§3.2, p. 7; §4, p. 17). This usefully isolates differences between functionals, but changes the original partition conventions, including the usual atomic-partition presentation of CD. It is not a comparison of every measure under its original definition of the minimum-information partition (MIP).

## Evidence and limits

The model family is Gaussian VAR(1), $X_{t+1}=AX_t+\epsilon_t$, with serially independent Gaussian innovations and spectral radius below one. Stationary covariance solves the discrete Lyapunov equation; lagged covariance and Gaussian conditional-covariance identities supply the information quantities (§4.1, pp. 16–18). Thus the comparisons can use known model statistics. They do not establish reliable estimation from finite, irregular, nonstationary application logs.

Three findings are especially informative:

* **Common input changes the verdict.** For two equally coupled nodes, increasing innovation correlation at coupling $a=0.4$ leaves TDMI and ΦG constant, sends Φ̃ toward infinity, drives ψ/Φ*/CD toward zero, and can make Φ negative (§4.2, Fig. 2, p. 18). The singular limit is approached from below; it is not an ordinary nonsingular Gaussian endpoint. In eight-node and random networks, some trends change: ψ can increase slightly with shared noise in Fig. 4, and mean Φ* increases with it in Fig. 7. The favorable summary in Table 4 should not erase those exceptions.
* **Topology is not the measured quantity.** At spectral radius 0.9, the unidirectional ring ranks first for Φ, Φ*, ΦG, and ψ; CD ranks the weighted Φ-optimal network first and the ring second (Table 3, p. 22). This is more precise than the nearby prose's broad claim about the ring. Footnote 7 reports a ranking change at radius 0.5. Neither edge count nor small-world structure gives a stable substitute for the dynamical calculation.
* **An intermediate peak is conditional evidence.** Erdős–Rényi comparisons average 50 networks per density/noise setting. Several measures peak at intermediate density or average correlation (§4.4, Figs. 7–8, pp. 23–25). Holding spectral radius fixed weakens individual edges as density grows; the peak therefore mixes topology with a coupling normalization choice. A roughly uniform correlation histogram addresses one sampling concern, but does not make the peak a universal signature of useful cognition.

Appendix A supplies corrected Gaussian decoder formulae and a concavity argument; the authors report checking their formula numerically against integration (pp. 28–31). Appendix B proves $CD\leq TDMI$ by the mutual-information chain rule (pp. 31–32). These are valuable mathematical contributions. There are no Navi-Brain measurements, cognitive-task improvements, human-consciousness experiments, or finite-sample estimator benchmarks here. Gaussianity, stationarity, observation grain, and partition convention remain substantive assumptions (§§5.1–5.3).

## Independent checks and disagreements

**The Gaussian ψ fails a disconnected-system control.** Box 3.3 defines partition union information as the largest single part's information about the whole future. Consider two independent stationary Gaussian AR(1) processes, each with coefficient $0<a<1$ and independent innovations. Let each process's TDMI be $m=-\tfrac12\log(1-a^2)>0$. Independence gives whole TDMI $2m$, while either past component supplies only $m$ about the joint future. The paper's formula consequently gives

$$
\psi=2m-\max(m,m)=m>0.
$$

There is only one nontrivial bipartition, so partition search cannot remove this result. The components have no cross-coupling or shared noise. In the same example CD and decoder-based Φ* are zero. This is an analytical consequence of the printed definition, **not an experiment performed for this review**. Footnote 6 acknowledges extending the Gaussian MMI formula from a univariate to a multivariate target (p. 11). Counting independent information about different future coordinates as synergistic may be acceptable for some predictive questions; it defeats a proposed interpretation as evidence that independent modules are integrated. This criticism concerns the paper's operationalization, not every PID-based measure.

**Equal-sized partition search remains exponential.** Section 5.1 prints $O(n^2)$ for exhaustive even-bipartition search (p. 26). For even $n$, the number of unordered equal-sized bipartitions is

$$
\frac12\binom{n}{n/2}=\Theta(2^n/\sqrt n),
$$

before paying for each measure evaluation. Eight nodes require 35 such partitions, versus 127 unrestricted bipartitions. This reduction is useful at small size; it does not justify scaling exhaustive search to the token network. Moreover, an equal-size restriction can miss the actual disconnection between unequal independent modules.

**The scale-invariance claim for Φ̃ is internally inconsistent.** Table 2 and Appendix C say it is not invariant to rescaling, even without normalization (pp. 6, 33). For a fixed partition, rescaling each component adds log-Jacobian terms to the conditional entropies in Eq. (17); the part terms sum to the whole term and cancel. Equivalently, Eqs. (18–19) express it as a sum of rescaling-invariant information quantities (p. 9). Unnormalized minimization over a fixed set of partitions preserves that invariance. Entropy-based MIP normalization can change partition selection under rescaling, but that is a different issue affecting Φ too. These statements and the partition-cost claim were visually verified in the PDF; they are not extraction artifacts.

## Mapping to Navi-Brain

The reference supports an **offline diagnostic research direction**, with observation and coupling infrastructure partly available. It does not support treating existing metrics as integrated information.

| Status | Verified implementation and implication |
|---|---|
| Implemented coupling | [WorkingMemory.php](../../../src/Core/WorkingMemory.php), `publish:122`, `contextForWork:223`, and [ExecutiveCore.php](../../../src/Core/ExecutiveCore.php), `enqueueWork:6318`, route selected state into ordinary worker prompts (I03, Q14). This gives concrete communication paths to study. Evidence-fenced jobs are excluded; other decision paths supply direct observation/retrieval text. |
| Implemented associative learning | [tokmem.c](../../../memories/src/tokmem.c), `index_associations:2536`, `query_memories:5631`, implements learned token associations and bounded one-hop retrieval (I02). Its adjacency matrix is not the VAR transition matrix of a measured stochastic process. |
| Partial observational basis | [ForwardModel.php](../../../src/Perception/ForwardModel.php), `observe:35`, retains sensor forecasts/errors; [DecisionStateMachine.php](../../../src/Core/DecisionStateMachine.php), `start:34`, records cycle state (I04, I07). Q02 establishes timing limitations: `reason_model_ms:273` includes queue delay, and stage totals need not include the entire asynchronous wait. These do not already provide a synchronized multivariate state series. |
| Implemented, different metrics | [ProbabilityScorer.php](../../../src/Core/ProbabilityScorer.php), line 56, and [OtherModel.php](../../../src/Core/OtherModel.php), `baseline:938`, calculate categorical uncertainty and observational forecasts. `ExecutiveCore::computeMetricVector:7994` supplies operational proxies (I10, Q15). None is Φ. |
| Absent within searched scope | Q15 reports no IIT/integrated-information evaluator or MIP calculation in the inspected production trees/dependencies. Q14 reports no neural activation capture interface in the inspected application boundary. This excludes neither external provider capabilities nor future instrumentation. |

Unlike intrinsic IIT evaluation, this paper's observational measures do **not** require obtaining a full intervention-defined transition kernel first. They do require declaring variables, boundary, sampling interval, lag, estimator, and stationarity criteria. NLP records, TTL-driven workspace changes, and asynchronous services are not automatically continuous Gaussian nodes. Shared user input, repeated prompt content, or common scheduler load can explain dependence. A diagnostic of executive records would concern that chosen abstraction, not the physical machine's consciousness or inaccessible model activations.

## Prioritized recommendations

**P0 — Specify an offline measurement contract and analytical controls before adding a dashboard metric.** Proposed location: a separate research analysis specification, referencing `computeMetricVector` only to distinguish existing labels. Name the measured system, lag, units, partition family, and intended meaning of zero. Use the disconnected autoregressions above, temporally independent common noise, coupled nodes, and unequal independent modules as controls. Compare Φ*, CD, and ψ with TDMI and instantaneous correlation; preserve disagreements. Cost is initially small analytical work, followed by an isolated numerical implementation if pursued. **Failure criterion:** a candidate claimed to detect cross-module integration fails if its population score is positive on independent predictive modules. The present Gaussian ψ already fails analytically. Reject it for that purpose unless its definition changes; do not conceal the failure by tuning a threshold.

**P1 — Establish whether the executive has suitable observations.** Proposed research adapter boundaries are `ForwardModel::observe`, `WorkingMemory::publish`, and decision/work completion events (I03–I04, I07, Q02). Start with a small, predefined observation vector from exported copies; distinguish wall-clock from event-indexed sampling and retain missingness and external-input indicators. Begin with CD and a small-system Φ* comparison, fitting only defensible stationary epochs. This requires export/instrumentation work and enough effective samples for covariance estimation; a ready-made synchronized export is **not verified** by the master. **Validation:** use temporally separated fitting/evaluation, autocorrelation-preserving nulls, covariance-conditioning checks, and prespecified lag/bin alternatives. Reject the Gaussian interpretation if residual diagnostics fail or the apparent cross-module gain disappears after accounting for observed common inputs. Confidence intervals should reflect serial dependence and partition selection.

**P2 — Connect any surviving diagnostic to functional benefit through isolated ablation.** Proposed experimental boundaries are `WorkingMemory::contextForWork` and `ExecutiveCore::enqueueWork`, with Q14's explicit exclusions and duplicate decision-context routes respected. In a separate replica, compare matched tasks with genuine shared context, independently shuffled context, and disconnected routes while holding budgets and external inputs comparable. Keep objective task outcomes primary; report metric changes separately. This is speculative engineering transfer, requiring reproducible inputs, model-run replication, and independently scored outcomes. **Failure criterion:** decline architecture adoption if the metric rewards repeated/shared text or extra traffic without improving held-out task performance, or loses its association after controlling those factors. A beneficial routing change need not increase every information measure.

## Verdict and coverage

**Adopt the comparative methodology; experiment with bounded offline diagnostics; reject a production “Φ” objective or consciousness score on this evidence.** The most consequential next step is the disconnected-predictive-module control: it exposes a failure in one of the paper's preferred measures before expensive Navi instrumentation begins. The equal-partition cost error and normalization distinction further argue against copying formulas and scaling claims uncritically.

Coverage: all substantive pages 1–34, including Appendices A–C; references on pp. 34–37 inspected. PDF and printed page numbers coincide. Essential claims were visually checked on pp. 9, 12, 26, and 33. Implementation mapping uses only the shared master's reference, re-read before finalization. No production inspection, tests, runtime actions, or experiments were performed. The availability of a synchronized export and the statistical suitability of future Navi observations remain unverified; no missing code fact is assumed in the verdict.
