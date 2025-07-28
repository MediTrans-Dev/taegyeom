-- QA 시스템 데이터베이스 생성
CREATE DATABASE IF NOT EXISTS qa_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 데이터베이스 사용
USE qa_system;

-- 번역가 테이블 생성
CREATE TABLE IF NOT EXISTS translators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    translator_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    join_date DATE NOT NULL,
    role ENUM('Translator', 'Reviewer', 'Manager') NOT NULL,
    status ENUM('Active', 'Inactive', 'Suspended') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 번역 QA 테이블 생성
CREATE TABLE IF NOT EXISTS translation_qas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    translator_id VARCHAR(50) NOT NULL,
    source TEXT NOT NULL,
    correction TEXT NOT NULL,
    error_desc TEXT,
    error_group VARCHAR(100),
    error_subgroup VARCHAR(100),
    severity ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
    flag VARCHAR(100),
    action VARCHAR(100),
    notified BOOLEAN DEFAULT FALSE,
    notified_date TIMESTAMP NULL,
    error_count INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (translator_id) REFERENCES translators(translator_id) ON DELETE CASCADE
);

-- 샘플 데이터 삽입 (번역가)
INSERT INTO translators (translator_id, name, email, join_date, role, status) VALUES
('TR001', '김번역', 'translator1@meditrans.co.kr', '2023-01-15', 'Translator', 'Active');
INSERT INTO translators (translator_id, name, email, join_date, role, status) VALUES
('TR002', '이검토', 'reviewer1@meditrans.co.kr', '2023-02-20', 'Reviewer', 'Active');
INSERT INTO translators (translator_id, name, email, join_date, role, status) VALUES
('TR003', '박관리', 'manager1@meditrans.co.kr', '2023-03-10', 'Manager', 'Active');

-- 샘플 데이터 삽입 (QA 데이터)
INSERT INTO translation_qas (translator_id, source, correction, error_desc, error_group, error_subgroup, severity, flag, action) VALUES
('TR001', 'The patient has severe asthma.', '환자는 심한 천식이 있습니다.', '의학 용어 번역 오류', 'Human Error', 'Terminology', 'Critical', 'QA 시트 기록 필요', '교육 발송');
INSERT INTO translation_qas (translator_id, source, correction, error_desc, error_group, error_subgroup, severity, flag, action) VALUES
('TR001', 'FEV1 was measured.', 'FEV1이 측정되었습니다.', '의학 용어 누락', 'Human Error', 'Terminology', 'Critical', 'QA 시트 기록 필요', '교육 발송');
INSERT INTO translation_qas (translator_id, source, correction, error_desc, error_group, error_subgroup, severity, flag, action) VALUES
('TR002', 'COPD symptoms include cough.', 'COPD 증상에는 기침이 포함됩니다.', '문법 오류', 'Human Error', 'Grammar', 'High', 'QA 시트 기록 필요', '경고 메일');
INSERT INTO translation_qas (translator_id, source, correction, error_desc, error_group, error_subgroup, severity, flag, action) VALUES
('TR003', 'The medication dosage is 10mg.', '약물 용량은 10mg입니다.', '형식 오류', 'Technical Error', 'Format', 'Low', 'DB 기록', '무시');

-- 인덱스 생성 (성능 향상)
CREATE INDEX idx_translator_id ON translation_qas(translator_id);
CREATE INDEX idx_error_group ON translation_qas(error_group);
CREATE INDEX idx_severity ON translation_qas(severity);
CREATE INDEX idx_flag ON translation_qas(flag);
CREATE INDEX idx_action ON translation_qas(action);
CREATE INDEX idx_notified ON translation_qas(notified);

-- 뷰 생성 (자주 사용하는 쿼리)
CREATE OR REPLACE VIEW qa_summary AS
SELECT 
    MAX(t.name) AS name,
    MAX(t.email) AS email,
    t.translator_id,
    COUNT(q.id) as total_errors,
    COUNT(CASE WHEN q.severity = 'Critical' THEN 1 END) as critical_errors,
    COUNT(CASE WHEN q.severity = 'High' THEN 1 END) as high_errors,
    COUNT(CASE WHEN q.severity = 'Medium' THEN 1 END) as medium_errors,
    COUNT(CASE WHEN q.severity = 'Low' THEN 1 END) as low_errors
FROM translators t
LEFT JOIN translation_qas q ON t.translator_id = q.translator_id
GROUP BY t.translator_id;

-- 권한 설정 (필요한 경우)
-- GRANT ALL PRIVILEGES ON qa_system.* TO 'root'@'localhost';
-- FLUSH PRIVILEGES; 