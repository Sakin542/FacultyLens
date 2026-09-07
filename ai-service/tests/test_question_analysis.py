from fastapi.testclient import TestClient
from app.main import app
from app.services.question_classifier import QuestionClassifier
from app.services.difficulty_analyzer import DifficultyAnalyzer
from app.services.cognitive_analyzer import CognitiveAnalyzer
from app.services.topic_detector import TopicDetector

client = TestClient(app)


def test_question_classifier_types():
    # MCQ
    mcq = QuestionClassifier.classify("Which of the following is a NoSQL database? (a) MySQL (b) MongoDB (c) PostgreSQL")
    assert mcq["question_type"] == "MCQ"

    # True/False
    tf = QuestionClassifier.classify("State whether true or false: Primary keys can contain NULL values.")
    assert tf["question_type"] == "TRUE_FALSE"

    # Problem Solving / SQL
    ps = QuestionClassifier.classify("Write a SQL query to calculate the average salary of employees per department.")
    assert ps["question_type"] == "PROBLEM_SOLVING"

    # Analytical
    ana = QuestionClassifier.classify("Analyze the differences between relational and non-relational database models.")
    assert ana["question_type"] == "ANALYTICAL"

    # Conceptual
    con = QuestionClassifier.classify("Define foreign key and state its purpose in relational integrity.")
    assert con["question_type"] == "CONCEPTUAL"

    # Descriptive
    desc = QuestionClassifier.classify("Explain in detail how multi-version concurrency control works in modern databases.")
    assert desc["question_type"] == "DESCRIPTIVE"


def test_difficulty_analyzer():
    easy = DifficultyAnalyzer.analyze("Define a primary key.")
    assert easy["level"] == "EASY"
    assert easy["method"] == "baseline"

    medium = DifficultyAnalyzer.analyze("Explain how database normalization reduces redundancy and prevents update anomalies.")
    assert medium["level"] == "MEDIUM"

    hard = DifficultyAnalyzer.analyze(
        "Design and justify a fault-tolerant, normalized distributed database architecture for a high-concurrency banking system with strict ACID constraints."
    )
    assert hard["level"] == "HARD"


def test_cognitive_analyzer_bloom_levels():
    remember = CognitiveAnalyzer.analyze("Define database normalization.")
    assert remember["level"] == "REMEMBER"

    understand = CognitiveAnalyzer.analyze("Explain the purpose of third normal form in relational design.")
    assert understand["level"] == "UNDERSTAND"

    apply = CognitiveAnalyzer.analyze("Apply normalization rules to decompose the following relation into 3NF.")
    assert apply["level"] == "APPLY"

    analyze = CognitiveAnalyzer.analyze("Analyze the given database schema and identify all functional dependency anomalies.")
    assert analyze["level"] == "ANALYZE"

    evaluate = CognitiveAnalyzer.analyze("Evaluate the trade-offs between B-Tree indexing and Hash indexing for range queries.")
    assert evaluate["level"] == "EVALUATE"

    create = CognitiveAnalyzer.analyze("Design a normalized relational database schema for a university management system.")
    assert create["level"] == "CREATE"


def test_topic_detector_with_course_topics():
    detector = TopicDetector()
    question = "Explain how 2NF and 3NF eliminate transitive dependencies in database tables."
    course_topics = [
        "Database Normalization",
        "Transaction Management",
        "Computer Networks",
        "Operating Systems",
    ]
    topics = detector.detect_topics(question, course_topics=course_topics)
    assert len(topics) > 0
    assert topics[0]["name"] == "Database Normalization"
    assert topics[0]["confidence"] >= 0.25


def test_analyze_single_question_api():
    payload = {
        "question": "Analyze the given distributed system and evaluate its fault-tolerance mechanisms.",
        "course_topics": ["Distributed Systems", "Database Indexing", "Web Development"],
    }
    response = client.post("/api/v1/analyze-question", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert "classification" in data
    assert data["classification"]["question_type"] in ["ANALYTICAL", "DESCRIPTIVE"]
    assert len(data["topics"]) > 0
    assert data["topics"][0]["name"] == "Distributed Systems"
    assert data["difficulty"]["level"] in ["MEDIUM", "HARD"]
    assert data["cognitive_level"]["level"] in ["EVALUATE", "ANALYZE"]


def test_analyze_batch_questions_api():
    questions = [
        {"number": 1, "text": "Define database normalization."},
        {"number": 2, "text": "Explain the difference between SQL and NoSQL databases."},
        {"number": 3, "text": "Design a normalized database schema for a university library."},
    ]
    payload = {
        "questions": questions,
        "course_topics": ["Normalization", "NoSQL", "Database Design", "Operating Systems"],
    }
    response = client.post("/api/v1/analyze-questions", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["status"] == "success"
    assert data["total_questions"] == 3
    assert len(data["questions"]) == 3

    # Check question 1
    q1 = data["questions"][0]
    assert q1["number"] == 1
    assert q1["cognitive_level"]["level"] == "REMEMBER"
    assert q1["difficulty"]["level"] == "EASY"

    # Check question 3
    q3 = data["questions"][2]
    assert q3["number"] == 3
    assert q3["cognitive_level"]["level"] == "CREATE"

    # Check summary distributions
    summary = data["summary"]
    assert "difficulty_distribution" in summary
    assert "cognitive_distribution" in summary
    assert "topics_detected" in summary


def test_analyze_question_validation():
    # Empty question
    res1 = client.post("/api/v1/analyze-question", json={"question": ""})
    assert res1.status_code == 422

    # Empty batch
    res2 = client.post("/api/v1/analyze-questions", json={"questions": []})
    assert res2.status_code == 422
