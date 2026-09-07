import math
import statistics
from typing import List, Dict, Any, Optional, Tuple

from app.config import get_settings
from app.schemas.quality import (
    QualityAnalysisRequest,
    QualityAnalysisResponse,
    QualityWeightsConfig,
    DifficultyTargetsConfig,
    AssessmentQuestionInput,
    TopicInput,
    LearningOutcomeInput,
    TopicCoverageItem,
    TopicAnalysisResult,
    LoCoverageItem,
    LoAnalysisResult,
    DifficultyDistributionItem,
    DifficultyAnalysisResult,
    CognitiveLevelItem,
    CognitiveAnalysisResult,
    QuestionTypeItem,
    QuestionDiversityResult,
    MarksAnalysisResult,
    ComponentScores,
)


class AssessmentQualityEngine:
    """
    Assessment Quality Engine for University Faculty Decision Support.
    Evaluates an examination across 6 pedagogical dimensions:
    1. Topic Coverage
    2. Learning Outcome Alignment & Coverage
    3. Difficulty Balance
    4. Cognitive Diversity (Bloom's Taxonomy)
    5. Question Format Diversity
    6. Marks Distribution & Assessment Integrity
    """

    BLOOM_LEVELS = ["Remember", "Understand", "Apply", "Analyze", "Evaluate", "Create"]
    DIFFICULTY_LEVELS = ["Easy", "Medium", "Hard"]
    QUESTION_FORMATS = [
        "MCQ",
        "Short Answer",
        "Descriptive",
        "Problem Solving",
        "True/False",
        "Conceptual",
        "Analytical",
    ]

    def __init__(self):
        self.settings = get_settings()

    def analyze(self, request: QualityAnalysisRequest) -> QualityAnalysisResponse:
        """Run comprehensive assessment quality evaluation across all 6 dimensions."""
        questions = request.questions
        topics = request.topics or []
        learning_outcomes = request.learning_outcomes or []
        assessment = request.assessment

        # Load weights and targets with defaults
        weights_config = request.weights or QualityWeightsConfig(
            topic=self.settings.quality_weight_topic,
            lo=self.settings.quality_weight_lo,
            difficulty=self.settings.quality_weight_difficulty,
            cognitive=self.settings.quality_weight_cognitive,
            question_diversity=self.settings.quality_weight_question_diversity,
            marks=self.settings.quality_weight_marks,
        )

        targets_config = request.difficulty_targets or DifficultyTargetsConfig(
            easy=self.settings.target_easy_percent,
            medium=self.settings.target_medium_percent,
            hard=self.settings.target_hard_percent,
        )

        findings: List[str] = []

        # 1. Topic Coverage Analysis
        topic_result, topic_findings = self._analyze_topic_coverage(questions, topics)
        findings.extend(topic_findings)

        # 2. Learning Outcome Coverage Analysis
        lo_result, lo_findings = self._analyze_lo_coverage(questions, learning_outcomes)
        findings.extend(lo_findings)

        # 3. Difficulty Balance Analysis
        diff_result, diff_findings = self._analyze_difficulty_balance(questions, targets_config)
        findings.extend(diff_findings)

        # 4. Cognitive Diversity Analysis
        cog_result, cog_findings = self._analyze_cognitive_diversity(questions)
        findings.extend(cog_findings)

        # 5. Question Diversity Analysis
        qdiv_result, qdiv_findings = self._analyze_question_diversity(questions)
        findings.extend(qdiv_findings)

        # 6. Marks Distribution Analysis
        marks_result, marks_findings = self._analyze_marks_distribution(questions, assessment)
        findings.extend(marks_findings)

        # Check for repetition / duplicate flags from STEP 12
        duplicate_count = sum(1 for q in questions if q.is_duplicate or (q.similarity_score and q.similarity_score >= 0.85))
        if duplicate_count > 0:
            findings.append(
                f"Historical Similarity: {duplicate_count} question(s) flagged for potential duplication with past exams. Faculty review recommended."
            )

        # Compute Overall Quality Score with Dynamic Weight Normalization
        component_scores = ComponentScores(
            topic_coverage=topic_result.score,
            learning_outcome_coverage=lo_result.score,
            difficulty_balance=diff_result.score,
            cognitive_diversity=cog_result.score,
            question_diversity=qdiv_result.score,
            marks_distribution=marks_result.score,
        )

        overall_score, rating, weights_applied, excluded = self._calculate_overall_score(
            component_scores, weights_config
        )

        return QualityAnalysisResponse(
            status="success",
            method="assessment_quality_engine",
            overall_quality_score=overall_score,
            rating=rating,
            weights_applied=weights_applied,
            excluded_components=excluded,
            components=component_scores,
            topic_analysis=topic_result,
            learning_outcome_analysis=lo_result,
            difficulty_analysis=diff_result,
            cognitive_analysis=cog_result,
            question_diversity_analysis=qdiv_result,
            marks_analysis=marks_result,
            findings=findings,
        )

    # -------------------------------------------------------------------------
    # 1. TOPIC COVERAGE
    # -------------------------------------------------------------------------
    def _analyze_topic_coverage(
        self, questions: List[AssessmentQuestionInput], topics: List[TopicInput]
    ) -> Tuple[TopicAnalysisResult, List[str]]:
        findings = []

        if not topics:
            return (
                TopicAnalysisResult(
                    status="UNAVAILABLE",
                    score=None,
                    methodology="No course syllabus topics defined. Topic representation cannot be evaluated.",
                    total_topics_defined=0,
                    covered_topics_count=0,
                    topics=[],
                ),
                ["Topic Coverage: No course syllabus topics defined for this course. Metric marked as unavailable."],
            )

        total_marks = sum(q.marks or 1.0 for q in questions) or 1.0
        covered_count = 0
        topic_items: List[TopicCoverageItem] = []

        for t in topics:
            t_name_lower = t.name.strip().lower()
            matching_q = []

            for q in questions:
                # Match via tagged topics or text mention
                q_topics = [qt.strip().lower() for qt in (q.topics or [])]
                if t_name_lower in q_topics or any(t_name_lower in qt for qt in q_topics):
                    matching_q.append(q)
                elif t_name_lower in q.text.lower():
                    matching_q.append(q)

            q_count = len(matching_q)
            q_marks = sum(q.marks or 1.0 for q in matching_q)
            cov_pct = (q_marks / total_marks) * 100.0

            if q_count >= 2 or cov_pct >= 15.0:
                status = "COVERED"
                covered_count += 1
            elif q_count >= 1:
                status = "ADEQUATE"
                covered_count += 1
            elif q_marks > 0:
                status = "LOW"
            else:
                status = "NOT_COVERED"

            topic_items.append(
                TopicCoverageItem(
                    topic=t.name,
                    question_count=q_count,
                    marks=round(q_marks, 2),
                    coverage_percentage=round(cov_pct, 2),
                    coverage_status=status,
                )
            )

        # Calculate score (unweighted or weighted if weights exist)
        has_weights = any(t.weight is not None and t.weight > 0 for t in topics)
        if has_weights:
            total_weight = sum(t.weight or 1.0 for t in topics)
            weighted_covered = sum(
                (t.weight or 1.0) for t, item in zip(topics, topic_items) if item.coverage_status in ("COVERED", "ADEQUATE")
            )
            score = (weighted_covered / total_weight) * 100.0
            methodology = "Weighted syllabus topic representation based on defined course module weights."
        else:
            score = (covered_count / len(topics)) * 100.0
            methodology = "Unweighted syllabus topic representation (adequately represented topics / total topics)."

        score = round(max(0.0, min(100.0, score)), 2)

        # Generate findings
        uncovered = [item.topic for item in topic_items if item.coverage_status == "NOT_COVERED"]
        if uncovered:
            findings.append(
                f"Topic Coverage: {len(uncovered)} syllabus topic(s) have no assessed questions ({', '.join(uncovered[:3])}{'...' if len(uncovered) > 3 else ''})."
            )
        elif covered_count == len(topics):
            findings.append("Topic Coverage: All defined syllabus topics are represented across the examination.")

        return (
            TopicAnalysisResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                total_topics_defined=len(topics),
                covered_topics_count=covered_count,
                topics=topic_items,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 2. LEARNING OUTCOME COVERAGE
    # -------------------------------------------------------------------------
    def _analyze_lo_coverage(
        self, questions: List[AssessmentQuestionInput], learning_outcomes: List[LearningOutcomeInput]
    ) -> Tuple[LoAnalysisResult, List[str]]:
        findings = []

        if not learning_outcomes:
            return (
                LoAnalysisResult(
                    status="UNAVAILABLE",
                    score=None,
                    methodology="No course learning outcomes defined. LO alignment cannot be evaluated.",
                    total_los_defined=0,
                    covered_los_count=0,
                    learning_outcomes=[],
                ),
                ["LO Coverage: No course learning outcomes configured. Metric marked as unavailable."],
            )

        total_marks = sum(q.marks or 1.0 for q in questions) or 1.0
        covered_count = 0
        lo_items: List[LoCoverageItem] = []

        for lo in learning_outcomes:
            lo_code_clean = lo.code.strip().upper()
            matching_q = [
                q
                for q in questions
                if q.learning_outcome_code and q.learning_outcome_code.strip().upper() == lo_code_clean
            ]

            q_count = len(matching_q)
            q_marks = sum(q.marks or 1.0 for q in matching_q)
            cov_pct = (q_marks / total_marks) * 100.0

            strongly_aligned = q_count  # Based on direct mapping
            weakly_aligned = 0

            if q_count >= 2 or cov_pct >= 15.0:
                status = "COVERED"
                covered_count += 1
            elif q_count == 1:
                status = "ADEQUATE"
                covered_count += 1
            elif q_count > 0 and weakly_aligned > strongly_aligned:
                status = "WEAK"
            else:
                status = "NOT_COVERED"

            lo_items.append(
                LoCoverageItem(
                    code=lo.code,
                    description=lo.description or "",
                    question_count=q_count,
                    marks=round(q_marks, 2),
                    strongly_aligned_questions=strongly_aligned,
                    weakly_aligned_questions=weakly_aligned,
                    coverage_percentage=round(cov_pct, 2),
                    coverage_status=status,
                )
            )

        # Calculate score
        has_weights = any(lo.weight is not None and lo.weight > 0 for lo in learning_outcomes)
        if has_weights:
            total_weight = sum(lo.weight or 1.0 for lo in learning_outcomes)
            weighted_covered = sum(
                (lo.weight or 1.0)
                for lo, item in zip(learning_outcomes, lo_items)
                if item.coverage_status in ("COVERED", "ADEQUATE")
            )
            score = (weighted_covered / total_weight) * 100.0
            methodology = "Weighted learning outcome coverage based on configured LO weightings."
        else:
            score = (covered_count / len(learning_outcomes)) * 100.0
            methodology = "Equal-weight learning outcome coverage (covered LOs / total LOs)."

        score = round(max(0.0, min(100.0, score)), 2)

        # Generate findings
        uncovered = [item.code for item in lo_items if item.coverage_status == "NOT_COVERED"]
        if uncovered:
            findings.append(
                f"LO Coverage: {len(uncovered)} Learning Outcome(s) have no mapped questions ({', '.join(uncovered)})."
            )
        elif covered_count == len(learning_outcomes):
            findings.append("LO Coverage: 100% of defined course learning outcomes are assessed.")

        return (
            LoAnalysisResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                total_los_defined=len(learning_outcomes),
                covered_los_count=covered_count,
                learning_outcomes=lo_items,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 3. DIFFICULTY BALANCE
    # -------------------------------------------------------------------------
    def _analyze_difficulty_balance(
        self, questions: List[AssessmentQuestionInput], targets: DifficultyTargetsConfig
    ) -> Tuple[DifficultyAnalysisResult, List[str]]:
        findings = []
        total_questions = len(questions)
        total_marks = sum(q.marks or 1.0 for q in questions) or 1.0

        # Check if difficulty is available
        valid_difficulties = [q.difficulty for q in questions if q.difficulty]
        if not valid_difficulties:
            return (
                DifficultyAnalysisResult(
                    status="UNAVAILABLE",
                    score=None,
                    methodology="No question difficulty classifications available.",
                    total_deviation=0.0,
                    distribution=[],
                ),
                ["Difficulty Balance: Question difficulties not classified. Metric marked as unavailable."],
            )

        # Normalize difficulty categories
        diff_counts = {"Easy": 0, "Medium": 0, "Hard": 0}
        diff_marks = {"Easy": 0.0, "Medium": 0.0, "Hard": 0.0}

        for q in questions:
            d_raw = (q.difficulty or "Medium").strip().capitalize()
            if d_raw not in diff_counts:
                d_raw = "Medium"
            diff_counts[d_raw] += 1
            diff_marks[d_raw] += q.marks or 1.0

        target_map = {
            "Easy": targets.easy,
            "Medium": targets.medium,
            "Hard": targets.hard,
        }

        distribution_items: List[DifficultyDistributionItem] = []
        total_deviation = 0.0

        for level in self.DIFFICULTY_LEVELS:
            q_cnt = diff_counts[level]
            q_pct = (q_cnt / total_questions) * 100.0
            m_val = diff_marks[level]
            m_pct = (m_val / total_marks) * 100.0
            tgt = target_map[level]
            dev = abs(m_pct - tgt)
            total_deviation += dev

            distribution_items.append(
                DifficultyDistributionItem(
                    level=level,
                    question_count=q_cnt,
                    question_percentage=round(q_pct, 2),
                    marks=round(m_val, 2),
                    marks_percentage=round(m_pct, 2),
                    target_percentage=round(tgt, 2),
                    deviation=round(dev, 2),
                )
            )

        # Score formula: max(0, 100 - Total Deviation * 0.5)
        score = max(0.0, min(100.0, 100.0 - (total_deviation * 0.5)))
        score = round(score, 2)
        methodology = f"Marks-weighted absolute deviation against target distribution (Easy: {targets.easy}%, Medium: {targets.medium}%, Hard: {targets.hard}%)."

        # Findings
        if total_deviation > 45.0:
            findings.append(
                f"Difficulty Balance: Assessment significantly deviates ({total_deviation:.1f}% total deviation) from standard target difficulty."
            )
        easy_pct = (diff_marks["Easy"] / total_marks) * 100.0
        hard_pct = (diff_marks["Hard"] / total_marks) * 100.0
        if easy_pct >= 60.0:
            findings.append(f"Difficulty Balance: High concentration of Easy questions ({easy_pct:.1f}% of marks).")
        elif hard_pct >= 50.0:
            findings.append(f"Difficulty Balance: Heavily weighted towards Hard questions ({hard_pct:.1f}% of marks).")

        return (
            DifficultyAnalysisResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                total_deviation=round(total_deviation, 2),
                distribution=distribution_items,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 4. COGNITIVE DIVERSITY (BLOOM'S TAXONOMY)
    # -------------------------------------------------------------------------
    def _analyze_cognitive_diversity(
        self, questions: List[AssessmentQuestionInput]
    ) -> Tuple[CognitiveAnalysisResult, List[str]]:
        findings = []
        total_questions = len(questions)
        total_marks = sum(q.marks or 1.0 for q in questions) or 1.0

        valid_cogs = [q.cognitive_level for q in questions if q.cognitive_level]
        if not valid_cogs:
            return (
                CognitiveAnalysisResult(
                    status="UNAVAILABLE",
                    score=None,
                    methodology="No cognitive levels classified.",
                    shannon_entropy=0.0,
                    max_possible_entropy=round(math.log(len(self.BLOOM_LEVELS)), 4),
                    dominant_level=None,
                    dominant_percentage=0.0,
                    distribution=[],
                ),
                ["Cognitive Diversity: Bloom cognitive levels not classified. Metric marked as unavailable."],
            )

        cog_counts = {level: 0 for level in self.BLOOM_LEVELS}
        cog_marks = {level: 0.0 for level in self.BLOOM_LEVELS}

        for q in questions:
            c_raw = (q.cognitive_level or "Understand").strip().capitalize()
            if c_raw not in cog_counts:
                c_raw = "Understand"
            cog_counts[c_raw] += 1
            cog_marks[c_raw] += q.marks or 1.0

        distribution_items: List[CognitiveLevelItem] = []
        dominant_level = None
        dominant_pct = 0.0

        # Normalized Shannon Entropy across N=6 Bloom categories
        entropy = 0.0
        n_categories = len(self.BLOOM_LEVELS)
        max_entropy = math.log(n_categories)

        for level in self.BLOOM_LEVELS:
            q_cnt = cog_counts[level]
            q_pct = (q_cnt / total_questions) * 100.0
            m_val = cog_marks[level]
            m_pct = (m_val / total_marks) * 100.0

            p_i = m_val / total_marks
            if p_i > 0:
                entropy -= p_i * math.log(p_i)

            if m_pct > dominant_pct:
                dominant_pct = m_pct
                dominant_level = level

            distribution_items.append(
                CognitiveLevelItem(
                    level=level,
                    question_count=q_cnt,
                    question_percentage=round(q_pct, 2),
                    marks=round(m_val, 2),
                    marks_percentage=round(m_pct, 2),
                )
            )

        # Normalized Entropy Score (0 to 100)
        score = (entropy / max_entropy) * 100.0 if max_entropy > 0 else 0.0
        score = round(max(0.0, min(100.0, score)), 2)
        methodology = "Normalized Shannon entropy H / ln(6) calculated across 6 Bloom taxonomy cognitive tiers."

        # Findings
        if dominant_pct >= 60.0 and dominant_level:
            findings.append(
                f"Cognitive Diversity: High concentration detected — {dominant_pct:.1f}% of marks are at the '{dominant_level}' level. Faculty review recommended."
            )

        higher_order_marks = sum(cog_marks[lvl] for lvl in ["Analyze", "Evaluate", "Create"])
        higher_order_pct = (higher_order_marks / total_marks) * 100.0
        if higher_order_pct >= 40.0:
            findings.append(
                f"Cognitive Diversity: Strong higher-order assessment ({higher_order_pct:.1f}% of marks in Analyze, Evaluate, Create)."
            )
        elif higher_order_pct == 0.0:
            findings.append("Cognitive Diversity: No higher-order cognitive questions (0% Analyze, Evaluate, Create).")

        return (
            CognitiveAnalysisResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                shannon_entropy=round(entropy, 4),
                max_possible_entropy=round(max_entropy, 4),
                dominant_level=dominant_level,
                dominant_percentage=round(dominant_pct, 2),
                distribution=distribution_items,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 5. QUESTION DIVERSITY
    # -------------------------------------------------------------------------
    def _analyze_question_diversity(
        self, questions: List[AssessmentQuestionInput]
    ) -> Tuple[QuestionDiversityResult, List[str]]:
        findings = []
        total_questions = len(questions)
        total_marks = sum(q.marks or 1.0 for q in questions) or 1.0

        valid_types = [q.question_type for q in questions if q.question_type]
        if not valid_types:
            return (
                QuestionDiversityResult(
                    status="UNAVAILABLE",
                    score=None,
                    methodology="No question types classified.",
                    unique_types_count=0,
                    shannon_entropy=0.0,
                    dominant_type=None,
                    dominant_percentage=0.0,
                    distribution=[],
                ),
                ["Question Diversity: Question formats not classified. Metric marked as unavailable."],
            )

        type_counts: Dict[str, int] = {}
        type_marks: Dict[str, float] = {}

        for q in questions:
            q_t = (q.question_type or "Descriptive").strip()
            # Title case
            q_t = q_t.replace("_", " ").title()
            type_counts[q_t] = type_counts.get(q_t, 0) + 1
            type_marks[q_t] = type_marks.get(q_t, 0.0) + (q.marks or 1.0)

        distribution_items: List[QuestionTypeItem] = []
        dominant_type = None
        dominant_pct = 0.0

        entropy = 0.0
        unique_types = len([t for t, cnt in type_counts.items() if cnt > 0])

        for q_type, q_cnt in type_counts.items():
            q_pct = (q_cnt / total_questions) * 100.0
            m_val = type_marks[q_type]
            m_pct = (m_val / total_marks) * 100.0

            p_i = m_val / total_marks
            if p_i > 0:
                entropy -= p_i * math.log(p_i)

            if m_pct > dominant_pct:
                dominant_pct = m_pct
                dominant_type = q_type

            distribution_items.append(
                QuestionTypeItem(
                    question_type=q_type,
                    question_count=q_cnt,
                    question_percentage=round(q_pct, 2),
                    marks=round(m_val, 2),
                    marks_percentage=round(m_pct, 2),
                )
            )

        # Normalize entropy against standard expectation of 4 diverse formats (ln(4))
        # If single format -> score = 0
        base_log = math.log(4)
        if unique_types <= 1:
            score = 0.0
        else:
            score = (entropy / base_log) * 100.0
        score = round(max(0.0, min(100.0, score)), 2)
        methodology = f"Normalized Shannon entropy over {unique_types} distinct question formats."

        # Findings
        if unique_types == 1 and dominant_type:
            findings.append(
                f"Question Diversity: Single format detected — 100% of questions are '{dominant_type}'. Consider diversifying question types if appropriate for the assessment format."
            )
        elif dominant_pct >= 75.0 and dominant_type:
            findings.append(
                f"Question Diversity: {dominant_pct:.1f}% of marks are concentrated in '{dominant_type}' format."
            )

        return (
            QuestionDiversityResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                unique_types_count=unique_types,
                shannon_entropy=round(entropy, 4),
                dominant_type=dominant_type,
                dominant_percentage=round(dominant_pct, 2),
                distribution=distribution_items,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 6. MARKS DISTRIBUTION & INTEGRITY
    # -------------------------------------------------------------------------
    def _analyze_marks_distribution(
        self, questions: List[AssessmentQuestionInput], assessment: Optional[Any] = None
    ) -> Tuple[MarksAnalysisResult, List[str]]:
        findings = []
        marks_list = [float(q.marks or 1.0) for q in questions]
        total_question_marks = sum(marks_list)

        expected_marks = None
        if assessment and assessment.total_marks is not None:
            expected_marks = float(assessment.total_marks)

        marks_match = True
        if expected_marks is not None and expected_marks > 0:
            marks_match = abs(total_question_marks - expected_marks) < 0.05

        avg_marks = statistics.mean(marks_list) if marks_list else 0.0
        min_marks = min(marks_list) if marks_list else 0.0
        max_marks = max(marks_list) if marks_list else 0.0
        median_marks = statistics.median(marks_list) if marks_list else 0.0

        # Find question with highest marks
        highest_q_num = None
        highest_share = 0.0
        if total_question_marks > 0:
            highest_share = (max_marks / total_question_marks) * 100.0
            for idx, q in enumerate(questions):
                if float(q.marks or 1.0) == max_marks:
                    highest_q_num = q.number or (idx + 1)
                    break

        high_concentration = highest_share >= 40.0 and len(questions) > 2

        # Breakdowns
        marks_by_topic: Dict[str, float] = {}
        marks_by_lo: Dict[str, float] = {}
        marks_by_diff: Dict[str, float] = {}
        marks_by_cog: Dict[str, float] = {}

        for q in questions:
            m = float(q.marks or 1.0)
            if q.topics:
                for t in q.topics:
                    marks_by_topic[t] = round(marks_by_topic.get(t, 0.0) + m, 2)
            if q.learning_outcome_code:
                lo_c = q.learning_outcome_code.strip()
                marks_by_lo[lo_c] = round(marks_by_lo.get(lo_c, 0.0) + m, 2)
            if q.difficulty:
                d_c = q.difficulty.strip().capitalize()
                marks_by_diff[d_c] = round(marks_by_diff.get(d_c, 0.0) + m, 2)
            if q.cognitive_level:
                c_c = q.cognitive_level.strip().capitalize()
                marks_by_cognitive_key = c_c
                marks_by_cog[marks_by_cognitive_key] = round(marks_by_cog.get(marks_by_cognitive_key, 0.0) + m, 2)

        # Score calculation
        score = 100.0
        if not marks_match and expected_marks is not None:
            score -= 25.0
            findings.append(
                f"Marks Mismatch: Sum of question marks ({total_question_marks:.1f}) does not match assessment target total ({expected_marks:.1f})."
            )

        if high_concentration:
            score -= 15.0
            findings.append(
                f"High Mark Concentration: Single question (#{highest_q_num}) accounts for {highest_share:.1f}% of total marks."
            )

        score = round(max(0.0, min(100.0, score)), 2)
        methodology = "Assessment marks summation integrity and single-question mark concentration checks."

        return (
            MarksAnalysisResult(
                status="AVAILABLE",
                score=score,
                methodology=methodology,
                total_question_marks=round(total_question_marks, 2),
                assessment_expected_marks=expected_marks,
                marks_match_assessment=marks_match,
                average_marks=round(avg_marks, 2),
                min_marks=round(min_marks, 2),
                max_marks=round(max_marks, 2),
                median_marks=round(median_marks, 2),
                high_concentration_detected=high_concentration,
                highest_single_question_share=round(highest_share, 2),
                highest_single_question_number=highest_q_num,
                marks_by_topic=marks_by_topic,
                marks_by_lo=marks_by_lo,
                marks_by_difficulty=marks_by_diff,
                marks_by_cognitive=marks_by_cog,
            ),
            findings,
        )

    # -------------------------------------------------------------------------
    # 7. OVERALL QUALITY SCORE CALCULATION
    # -------------------------------------------------------------------------
    def _calculate_overall_score(
        self, components: ComponentScores, weights: QualityWeightsConfig
    ) -> Tuple[Optional[float], str, Dict[str, float], List[str]]:
        raw_weights = {
            "topic": (components.topic_coverage, weights.topic),
            "learning_outcome": (components.learning_outcome_coverage, weights.lo),
            "difficulty": (components.difficulty_balance, weights.difficulty),
            "cognitive": (components.cognitive_diversity, weights.cognitive),
            "question_diversity": (components.question_diversity, weights.question_diversity),
            "marks": (components.marks_distribution, weights.marks),
        }

        available_scores = {}
        excluded = []

        for key, (score, weight) in raw_weights.items():
            if score is not None:
                available_scores[key] = (score, weight)
            else:
                excluded.append(key)

        if len(available_scores) < 2:
            return None, "UNAVAILABLE", {}, excluded

        total_available_weight = sum(w for _, w in available_scores.values())
        if total_available_weight <= 0:
            return None, "UNAVAILABLE", {}, excluded

        weighted_sum = 0.0
        normalized_weights: Dict[str, float] = {}

        for key, (score, weight) in available_scores.items():
            norm_w = (weight / total_available_weight) * 100.0
            normalized_weights[key] = round(norm_w, 2)
            weighted_sum += score * (norm_w / 100.0)

        overall_score = round(max(0.0, min(100.0, weighted_sum)), 2)

        # Map to rating
        if overall_score >= 90.0:
            rating = "EXCELLENT"
        elif overall_score >= 80.0:
            rating = "GOOD"
        elif overall_score >= 70.0:
            rating = "FAIR"
        elif overall_score >= 60.0:
            rating = "NEEDS_REVIEW"
        else:
            rating = "REQUIRES_ATTENTION"

        return overall_score, rating, normalized_weights, excluded
