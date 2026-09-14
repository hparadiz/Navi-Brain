# DanceHMR: Hand-Aware Whole-Body Human Mesh Recovery from Monocular Videos

Wenhao Shen, Ming Zhou, Hengyuan Zhang, Siyuan Bian, Youjiang Xu, and Xi Lin; ByteDance Intelligent Creation; 2026. **Version reviewed:** local arXiv:2605.18102v1, masthead dated 18 May 2026; cover separately says “Date: May 19, 2026.” No publication venue is stated in this version. [Local PDF](../../../assets/papers-inbox/2605.18102v1.pdf). **Reviewer:** GPT-6 Astra. **Implementation evidence:** [shared implementation reference](IMPLEMENTATION.md), snapshot 2026-09-06, HEAD `ee1590009d6fd4c48035c63e15b3896a585757d7`, including the subsequently supplied QDANCE clarification.

## Core contribution

DanceHMR is a supervised video-to-human-mesh estimator, relevant to acquiring expressive motion and visual body observations. Its contribution is joint temporal reconstruction of body and hands under occlusion and close-up framing. It supplies no cognitive executive, embodied action policy, or theory of consciousness.

The central distinction is **fusing local evidence before reconstructing motion**. Frozen HMR2.0 and HaMeR networks provide body and hand image features; ViTPose and RTMW provide keypoints. The trainable network builds a body token from keypoints, image features, camera-aware bounding-box information, and camera angular velocity. Separate left/right streams encode hand features, keypoints, and boxes. Their concatenation passes through a fusion MLP and becomes a residual addition to the body token:

\[
z_t=z_t^b+\phi_h([z_t^{lh},z_t^{rh}]),\qquad h_{1:T}=\mathcal{T}(z_{1:T}).
\]

These are Eqs. 4–5, PDF p. 5, §3.2.3. Hand branches supply evidence rather than independent hand poses subsequently attached at the wrists. Shared temporal decoding can therefore use anatomical context and neighboring observations to resolve wrist orientation and finger articulation together. The temporal model uses relative attention and a local window; the reported window size is 120 (§4.1, p. 7).

The decoder predicts SMPL-X pose and shape, camera-space orientation/translation, gravity-view orientation, local root velocity, and selected foot/wrist contact states (§3.1.1, p. 4; §3.2.4, p. 5). Optional contact-aware root-velocity refinement targets sliding and jitter. This is a structured reconstruction prior, not demonstrated physical simulation or grasp control.

Missing observations receive specific treatment. Low-confidence keypoints become learned missing-joint embeddings. Training masks hand observations below confidence/visibility/size thresholds: keypoint confidence 0.55, at least five valid visible keypoints, and minimum hand-box scale 20 pixels. A confidence-weighted reprojection loss supervises reliable hand observations (§§3.2.2, 3.3.2, 3.4.3; pp. 5–7). Residual addition itself is **not an explicit calibrated reliability gate**; robustness also depends on these masks, training, and learned temporal priors.

Close-up augmentation uses posture-dependent crops, clip-shared perturbations, and recomputed hand visibility. Stage I trains 207k steps on a broad dataset mixture; Stage II trains another 88k steps with more hand-rich SMPL-X data and stronger distal/fingertip supervision (§§3.3–3.4, pp. 5–7). The method assumes useful pretrained features, sufficiently informative temporal neighbors, and anatomical/motion priors that remain appropriate when direct observations disappear.

## Evidence and its strength

The empirical case is strongest for hand reconstruction and temporal accuracy. Table 1 (p. 8) gives the following comparisons; all errors below are millimeters and lower is better:

| Dataset and metric | Comparator | DanceHMR |
|---|---:|---:|
| ARCTIC hand PA-PVE | SMPLest-X: 11.9 | 8.5 |
| ARCTIC hand PVE | SMPLest-X: 28.8 | 24.8 |
| ARCTIC whole-body PVE | SMPLest-X: 43.5 | 44.7 |
| UBody hand PVE | SMPLest-X: 37.7 | 22.2 |
| UBody hand PA-PVE | AiOS: 7.3 | 7.4 |

The ARCTIC hand improvements against SMPLest-X are 28.6% aligned and 13.9% unaligned vertex error. However, DanceHMR does not dominate every metric: SMPLest-X retains better ARCTIC overall vertex accuracy, and AiOS slightly leads UBody aligned hand vertex error. Procrustes-aligned measurements remove alignment differences and cannot alone establish correct camera/world placement.

Table 3 (p. 9) includes the more relevant temporal comparator, GVHMR+HaMeR. DanceHMR reduces hand jitter from 7.4 to 4.2 m/s³ and hand MPJVE from 100.7 to 67.7 mm/s: 43.2% and 32.8% reductions. Lower velocity error against ground truth strengthens the inference beyond merely suppressing high-frequency motion. Nevertheless, an explicitly tuned smoothing baseline and frequency-dependent motion analysis would better separate improved reconstruction from oversmoothing. Temporal metrics are reported on ARCTIC, not UBody or the internet examples.

Body results are competitive (Table 2, p. 8): EMDB MPJPE falls from DUOMO's 67.1 to 64.3 mm, while PVE falls from 78.2 to 75.4 mm. On 3DPW, DanceHMR reports 53.6 mm MPJPE, 65.1 mm PVE, and 4.9 m/s² acceleration error, although GENMO has lower PA-MPJPE, 34.6 versus 35.2 mm. The paper's broad “state-of-the-art” wording should be read metric by metric and against the listed comparisons.

The five ARCTIC ablations support contributions from both architecture and training (Table 4, p. 9). Relative to full-model hand PVE/jitter of 24.8/4.2, replacing residual fusion with early fusion gives 28.2/6.6; removing hand observations gives 44.8/10.1; removing close-up augmentation gives 31.1/5.9; removing Stage II gives 31.7/4.8; removing visibility-aware supervision gives 27.3/5.7. These are useful within-system interventions. They do not isolate every mechanism: the no-Stage-II comparison removes additional training as well as its curriculum, and no equal-step alternative is reported. Nor is there a standalone no-temporal-context ablation or a factorial analysis of interactions.

## Limitations and disagreements

**Deployment efficiency is unresolved.** The approximately 46M-parameter claim concerns the trainable reconstruction model, alongside frozen feature extractors and detectors (§4.1, p. 7; §4.2.1, p. 8). Comparing that figure with much larger trainable whole-body models does not establish end-to-end memory, throughput, energy, or latency advantages. No hardware/runtime benchmark is supplied. The 120-frame window spans four seconds at an illustrative 30 fps; this is context duration, not a measured four-second delay. Causal masking, required lookahead, and streaming window boundaries are unspecified, so interactive performance cannot be inferred from offline reconstruction.

**Plausibility is not observation.** Figures 3–5 (pp. 13–14) visually support improved visible-hand alignment in selected difficult examples. Hidden limbs can look plausible without matching their unobserved true pose. Static panels, including two adjacent frames in Figure 4, cannot independently establish the claimed video smoothness. The referenced supplementary video was not part of the supplied source. There is no uncertainty calibration, controlled occlusion-duration curve, dedicated wrist-consistency metric, or measured contact/foot-sliding result. Inclusion of jaw and eye pose in SMPL-X also does not establish expressive-face accuracy.

**Reproducibility is incomplete in v1.** Training includes ARCTIC and 3DPW, which are also evaluation datasets; this requires explicit disjoint split verification, not an assumption of leakage or zero-shot evaluation. The paper does not spell out those partitions, mixture ratios, full loss weights, optimization details, or the optional refinement setting for each result. It reports no seed variation or confidence intervals. Equation 9's normalization by total valid hand weight also leaves the all-masked case unspecified. Availability of usable checkpoints/code and external component licensing was not checked. These gaps limit reproduction and adoption, without negating the reported comparisons.

## Mapping to the verified Navi-Brain implementation

The master's [QDANCE](IMPLEMENTATION.md#qdance--video-reconstruction-and-avatar-motion-boundaries) resolves the important architectural boundary:

| Status | Verified implementation and implication |
|---|---|
| Implemented | [DesktopAwareness.php](../../../src/Perception/DesktopAwareness.php), `look:88`, captures a single desktop image; [LocalVisualObserver.php](../../../src/Perception/LocalVisualObserver.php), `observe:22`, returns scene/activity summaries. Neither output is an articulated pose sequence. |
| Partially suitable infrastructure | [SensoryCortex.php](../../../src/Perception/SensoryCortex.php), `ingest:234`, accepts authorized structured payloads with optional sequence and observation time; [SenseReading.php](../../../src/Model/SenseReading.php), `:25–53`, persists source/time/sequence/payload/digest/expiry. Geometry, identity tracking, frame rate, calibration, and pose-validity contracts would be additions. |
| Absent after bounded search | QDANCE found no timestamped video-frame pipeline, SMPL/SMPL-X/MANO-equivalent estimator, or pose-to-avatar retargeting producer/consumer in the inspected Navi-Brain src/bin PHP/Python paths. Generic PerceptCodec “frames” are sensory windows, not evidence of video processing. |
| External capability unverified | [PetSpeechActuator.php](../../../src/Perception/PetSpeechActuator.php), `health:17`/`speak:59`, exposes HTTP text speech in the inspected caller. Navi-Body itself and other external services were not inspected; their possible animation interfaces remain unverified. |

Existing temporal sensory prediction is also different. [Q04](IMPLEMENTATION.md#q04--workspace-to-sensory-feedback-scope) verifies [ForwardModel.php](../../../src/Perception/ForwardModel.php), `predict:267`, extrapolating numeric values and persisting categorical/text values. It supplies neither articulated geometric constraints nor a learned body-hand transformer. [Q13](IMPLEMENTATION.md#q13--attention-schema-and-sensory-monitor-uptake) distinguishes forecast precision from external perceptual accuracy: a stable wrong stream can be predictable. DanceHMR's detector validity should not be equated with that precision or with workspace salience.

Consequently, transfer is strongest at a future perception/animation service boundary. The general idea of preserving specialist observations and explicit missingness is useful engineering guidance. It does not justify replacing [WorkingMemory.php](../../../src/Core/WorkingMemory.php) role slots or capsule competition with a residual neural fusion mechanism ([I03](IMPLEMENTATION.md#i03--working-memory-attention-workspace-broadcast)); the representations, objectives, and supervision are different.

## Prioritized recommendations and falsifiable criteria

These are proposed evaluations and changes; none were performed.

1. **P0 — Monitor for cognition; make no executive or memory change on this evidence.** Treat DanceHMR as a candidate external reconstruction component. Before opening an integration task, obtain a reproducible artifact, establish its full dependency cost, and identify a concrete recorded-motion or human-pose consumer. QDANCE's source boundary is the prospective location; no verified pose-output contract exists. This documentation-first decision costs little. Revisit it only if an end-to-end demonstration produces usable poses or avatar motion with recorded resource/quality measurements; a mesh demo without the needed downstream consumer fails the integration gate.

2. **P1 — If motion acquisition becomes a requirement, run an isolated reconstruction comparison.** Keep feature extraction, temporal inference, camera geometry, and any avatar retargeting outside PHP executive scheduling; publish bounded summaries/references through `SensoryCortex::ingest` only after evaluation. This requires runnable models, disjoint evaluation clips, GPU profiling, and a separately verified retargeter if animation is the goal. Compare DanceHMR against GVHMR+HaMeR with tuned smoothing on held-out close-up, occlusion, and rapid-finger-motion clips. Measure unaligned hand PVE, MPJVE, jitter, whole-body PVE, occlusion-duration error, peak GPU memory, and end-to-end latency, including preprocessing/refinement. Suggested preregistered gates are at least 10% lower hand PVE and 20% lower hand MPJVE, with no more than 5% whole-body PVE regression, supported by paired clip-level uncertainty estimates. These are proposed engineering thresholds, not paper findings. Reject promotion if improvements vanish against smoothing, erase fast articulation, or depend on future frames incompatible with the declared use. Evaluate offline and bounded-lookahead operation separately.

3. **P2 — Preserve observation validity if a pose source is added; this is speculative synthesis.** At the new source adapter, `SenseReading` payload, and sensory-to-workspace publication boundary (`SensoryCortex::evaluate:311`, publication at `:412`; Q04), carry track identity, coordinate frame/units, observation time, visible-joint mask, detector confidence, and inferred-versus-observed status. Keep geometric reconstruction and raw meshes outside generic scalar forecasting. This follows the paper's missing-observation treatment, but its calibrated epistemic interpretation would be new work. Depend on an annotated replay set and explicit schema consumers. Compare masked/provenance-bearing summaries with unqualified completed poses under hand exit/re-entry, sustained occlusion, stale packets, and confidently wrong detections. Any hidden/stale joint promoted to current direct observation fails the contract; calibrated geometry claims additionally require decreasing held-out error as confidence rises. Smoothness or source predictability alone does not pass.

## Verdict and coverage

**Monitor for Navi-Brain cognition; conditionally experiment for external motion acquisition.** The persuasive result is improved articulated-hand accuracy and temporal behavior from joint body-hand evidence, not a transferable cognitive architecture. The most consequential next step is an end-to-end, full-cost reproduction against a smoothed temporal baseline before committing to a new pose pipeline.

Coverage: all 14 local PDF pages, including methods, Tables 1–4, conclusion, references, and final qualitative pages; no separate appendix is present. Equations on pp. 5 and 7, Tables 3–4 on p. 9, and Figures 3–5 on pp. 13–14 were visually checked against rendered PDF pages. No external supplementary video, released implementation, runtime state, or experiments were examined. QDANCE answered the repository implementation question; external Navi-Body capabilities remain explicitly unverified. Source SHA-256: `81f7f45e0b34fe47539d12b37c155192b093e9ed1065f5b4726140f3e643560d`.
