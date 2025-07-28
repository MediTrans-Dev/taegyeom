<?php
/*
You are a Translation QA Automation Roadmap Assistant.

Current system covers:
1. Translator metadata registration
2. Excel/CSV QA segment import
3. Automated error classification (group, subgroup, severity, human error)
4. Action & flag decision (training, warning, exclusion, QA vs DB record)
5. Google Sheets & DB synchronization
6. Email notifications (training & warning)
7. Excel/CSV export
8. Table UI with inline edit, sort, filter, export

We want to refine the roadmap with:
- Features to ADD:
  * Domain-specific glossary validation
  * AI-assisted contextual error correction suggestions
  * Real-time translation memory integration
  * Dashboard monitoring & analytics
  * User role & permission management
- Features to REMOVE or DEPRECATE:
  * CLI-only batch export scripts (replace with API-driven export)
  * Static PHP page forms for QA input (use table UI instead)
  * Manual "exported" flag column (automate status via webhook)
  * Hard-coded email templates (move to template engine)
  * Legacy Python CSV scripts

Please produce:
1. A clear roadmap outline with milestones (3–6 months horizon)
2. Prioritized backlog of ADD and REMOVE items
3. Suggested API endpoints or modules for each new feature
4. Deprecation plan for removed features and data migration steps
*/

/**
 * 데이터베이스 연결 함수
 */
function createEngine($database_url, $user, $pass) {
    try {
        $pdo = new PDO($database_url, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("데이터베이스 연결 실패: " . $e->getMessage());
    }
}

/**
 * 번역가 등록 함수
 */
function registerTranslator($pdo, $data) {
    try {
        $query = "
            INSERT INTO translators (translator_id, name, email, join_date, role, status)
            VALUES (:translator_id, :name, :email, :join_date, :role, :status)
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($data);
        return true;
    } catch (PDOException $e) {
        throw new Exception("번역가 등록 실패: " . $e->getMessage());
    }
}



/**
 * 번역가 목록 조회 함수
 */
function getTranslators($pdo) {
    try {
        $query = "SELECT * FROM translators ORDER BY translator_id";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * translation_qas 테이블에 한 행을 저장하는 함수
 */
function saveQARowToDB($pdo, $row) {
    try {
        $query = "
            INSERT INTO translation_qas (
                translator_id, source, target, correction, error_desc,
                error_group, error_subgroup, severity, human_error, flag, action, auto_confidence
            ) VALUES (
                :translator_id, :source, :target, :correction, :error_desc,
                :error_group, :error_subgroup, :severity, :human_error, :flag, :action, :auto_confidence
            )
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':translator_id'    => $row['translator_id'] ?? null,
            ':source'           => $row['source'] ?? null,
            ':target'           => $row['target'] ?? null,
            ':correction'       => $row['correction'] ?? null,
            ':error_desc'       => $row['error_desc'] ?? null,
            ':error_group'      => $row['error_group'] ?? null,
            ':error_subgroup'   => $row['error_subgroup'] ?? null,
            ':severity'         => $row['severity'] ?? null,
            ':human_error'      => $row['human_error'] ?? null,
            ':flag'             => $row['flag'] ?? null,
            ':action'           => $row['action'] ?? null,
            ':auto_confidence'  => $row['auto_confidence'] ?? null,
        ]);
        
        // 디버깅: 저장 성공 로그
        error_log("DB 저장 성공 - Translator ID: " . ($row['translator_id'] ?? 'null') . ", Source: " . substr($row['source'] ?? '', 0, 50));
        return true;
    } catch (PDOException $e) {
        error_log("DB 저장 실패: " . $e->getMessage() . " - Data: " . json_encode($row));
        throw new Exception("DB 저장 실패: " . $e->getMessage());
    }
}

/**
 * Python의 pd.read_sql() 대체 - DB에서 필터링된 레코드 조회
 */
function readSql($pdo, $query) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        if (empty($results)) {
            echo "조회된 데이터가 없습니다.\n";
        }
        
        return $results;
    } catch (PDOException $e) {
        throw new Exception("SQL 쿼리 실행 실패: " . $e->getMessage());
    }
}

/**
 * Python의 df.to_excel() 대체 - 엑셀 내보내기 (CSV로 대체)
 */
function toExcel($data, $filename = null) {
    if ($filename === null) {
        $filename = 'QA_Output_' . date('Y-m-d_H-i-s') . '.csv';
    }
    
    // 파일 경로 보안 검사
    $filename = basename($filename); // 경로 조작 방지
    $output_dir = __DIR__ . '/exports/';
    
    // exports 디렉토리 생성
    if (!is_dir($output_dir)) {
        if (!mkdir($output_dir, 0755, true)) {
            throw new Exception("디렉토리 생성 실패: {$output_dir}");
        }
    }
    
    $full_path = $output_dir . $filename;
    $file = fopen($full_path, 'w');
    if (!$file) {
        throw new Exception("파일 생성 실패: {$full_path}");
    }
    
    // BOM 추가 (Excel에서 한글 깨짐 방지)
    fwrite($file, "\xEF\xBB\xBF");
    
    // 헤더 작성
    if (!empty($data)) {
        fputcsv($file, array_keys($data[0]));
        
        // 데이터 작성
        foreach ($data as $row) {
            fputcsv($file, $row);
        }
    } else {
        echo "내보낼 데이터가 없습니다.\n";
        fclose($file);
        return null;
    }
    
    fclose($file);
    echo "데이터가 {$full_path} 파일로 내보내기 완료되었습니다.\n";
    return $full_path;
}

/**
 * Python의 smtplib.SMTP() 대체 - 이메일 발송
 */
function sendMail($to_email, $subject, $body) {
    // 이메일 설정
    $SENDER_EMAIL = "no-reply@meditrans.co.kr";
    $SMTP_SERVER = "smtp.gmail.com";
    $USER = "project_management@meditrans.co.kr";
    $PASS = "@Meditrans2026!";
    
    // 이메일 유효성 검사
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        echo "잘못된 이메일 주소: {$to_email}\n";
        return false;
    }
    
    // PHP의 mail() 함수 사용 (실제 운영에서는 PHPMailer 등 사용 권장)
    $headers = "From: {$SENDER_EMAIL}\r\n";
    $headers .= "Reply-To: project_management@meditrans.co.kr\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    try {
        $result = mail($to_email, $subject, $body, $headers);
        if ($result) {
            echo "이메일 발송 완료: {$to_email}\n";
            return true;
        } else {
            echo "이메일 발송 실패: {$to_email}\n";
            return false;
        }
    } catch (Exception $e) {
        echo "이메일 발송 오류: {$to_email}, 오류: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Python의 engine.execute() 대체 - SQL 실행
 */
function executeSql($pdo, $query, $params = []) {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        throw new Exception("SQL 실행 실패: " . $e->getMessage());
    }
}

/**
 * 이메일 템플릿 정의 (Python의 TEMPLATE_MAP 대체)
 */
$TEMPLATE_MAP = [
    "교육 발송" => "
안녕하세요,

번역 품질 관리 시스템에서 Human Error가 2회차 발생하여 교육 자료를 발송드립니다.

주요 개선 사항:
- 번역 전 문맥 파악 강화
- 의학 용어 사전 참조 필수
- 번역 후 검토 과정 추가

교육 자료는 첨부파일을 참고하시기 바랍니다.

감사합니다.
메디트랜스 QA팀
",
    
    "경고 메일" => "
안녕하세요,

번역 품질 관리 시스템에서 번역 오류가 반복 발생하여 경고 메일을 발송드립니다.

발생 오류:
- 오류 유형: {error_type}
- 심각도: {severity}
- 발생 횟수: {error_count}회

개선 요청사항:
1. 번역 전 원문 재검토
2. 의학 용어 정확성 확인
3. 번역 후 교정 과정 강화

지속적인 오류 발생 시 프로젝트 참여가 제한될 수 있습니다.

감사합니다.
메디트랜스 QA팀
"
];

/**
 * 엑셀 파일 파싱 함수
 */
function parseExcelFile($file_path) {
    try {
        if (!file_exists($file_path)) {
            throw new Exception("파일이 존재하지 않습니다: {$file_path}");
        }
        
        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        if ($file_extension === 'csv') {
            return parseCSVFile($file_path);
        } elseif ($file_extension === 'xlsx') {
            return parseXLSXFile($file_path);
        } else {
            throw new Exception("지원하지 않는 파일 형식입니다. CSV 또는 XLSX 파일을 업로드해주세요.");
        }
        
    } catch (Exception $e) {
        throw new Exception("파일 파싱 실패: " . $e->getMessage());
    }
}

/**
 * QA 엑셀 파일 자동 파싱 (QA Details 시트)
 */
function parseQADetailsSheet($file_path) {
    try {
        // 간단한 CSV 파싱으로 대체 (실제로는 PhpSpreadsheet 라이브러리 사용 권장)
        $data = [];
        $file = fopen($file_path, 'r');
        
        if (!$file) {
            throw new Exception("파일을 열 수 없습니다.");
        }
        
        // BOM 제거
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }
        
        // 헤더 읽기 (QA Details 시트 헤더 - 정확한 구조)
        $headers = fgetcsv($file);
        if (!$headers) {
            throw new Exception("헤더를 읽을 수 없습니다.");
        }
        
        // 디버깅: 감지된 헤더 로깅
        error_log("QA 파싱 - 감지된 헤더: " . implode(', ', $headers));
        
        // 정확한 QA Details 헤더 검증
        $expected_headers = [
            'Segment/Line #',
            'Source', 
            'Translation',
            'Back Translation',
            'Description of Error',
            'Error Category (대분류)',
            'Error Category (소분류)',
            'Human Error 여부',
            'Severity'
        ];
        
        // 헤더 검증 (더 유연하게)
        if (count($headers) < 5) {
            throw new Exception("QA Details 시트 헤더가 올바르지 않습니다. 최소 5개 컬럼(Source, Translation 포함)이 필요합니다. 현재: " . count($headers) . "개");
        }
        
        // 필수 헤더 확인
        $required_headers = ['Source', 'Translation'];
        $missing_headers = [];
        foreach ($required_headers as $required) {
            if (!in_array($required, $headers)) {
                $missing_headers[] = $required;
            }
        }
        
        if (!empty($missing_headers)) {
            throw new Exception("필수 헤더가 누락되었습니다: " . implode(', ', $missing_headers) . " - 현재 헤더: " . implode(', ', $headers));
        }
        
        // 데이터 읽기
        while (($row = fgetcsv($file)) !== false) {
            // 빈 행 건너뛰기
            if (empty(array_filter($row))) {
                continue;
            }
            
            // 헤더와 데이터 컬럼 수가 다르면 건너뜀
            if (count($row) !== count($headers)) {
                error_log("QA 파싱 - 컬럼 수 불일치: 헤더 " . count($headers) . "개, 데이터 " . count($row) . "개");
                continue;
            }
            
            $row_data = array_combine($headers, $row);
            
            // 필수 필드 확인 (Source와 Translation은 필수)
            if (!empty($row_data['Source']) && !empty($row_data['Translation'])) {
                // 유연한 헤더 매핑
                $mapped_data = [
                    'segment_line' => $row_data['Segment/Line #'] ?? $row_data['Segment'] ?? $row_data['Line #'] ?? $row_data['Line'] ?? '',
                    'source' => $row_data['Source'] ?? '',
                    'target' => $row_data['Translation'] ?? $row_data['Target'] ?? '',
                    'back_translation' => $row_data['Back Translation'] ?? $row_data['BackTranslation'] ?? $row_data['Back'] ?? '',
                    'error_desc' => $row_data['Description of Error'] ?? $row_data['Error Description'] ?? $row_data['Description'] ?? $row_data['Error Desc'] ?? '',
                    'error_group' => $row_data['Error Category (대분류)'] ?? $row_data['Error Category'] ?? $row_data['Category'] ?? $row_data['Error Group'] ?? '',
                    'error_subgroup' => $row_data['Error Category (소분류)'] ?? $row_data['Subcategory'] ?? $row_data['Sub Category'] ?? '',
                    'human_error' => $row_data['Human Error 여부'] ?? $row_data['Human Error'] ?? $row_data['HumanError'] ?? '',
                    'severity' => $row_data['Severity'] ?? $row_data['Error Severity'] ?? '',
                    'translator_id' => 'AUTO_' . date('YmdHis') . '_' . rand(1000, 9999),
                    'correction' => $row_data['Translation'] ?? $row_data['Target'] ?? '' // 수정문은 번역문과 동일
                ];
                
                $data[] = $mapped_data;
            }
        }
        
        fclose($file);
        return $data;
        
    } catch (Exception $e) {
        throw new Exception("QA Details 시트 파싱 실패: " . $e->getMessage());
    }
}

/**
 * QA 통계 자동 계산 (QA Evaluation 시트 기반)
 */
function calculateQAStatistics($qa_data) {
    $stats = [
        'total_segments' => count($qa_data),
        'total_words' => 0, // Word Count 계산
        'error_categories' => [
            'Accuracy' => [
                'Addition/Omission' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Consistency' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Mistranslation' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Untranslation' => ['minor' => 0, 'major' => 0, 'critical' => 0]
            ],
            'Language' => [
                'Grammar' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Punctuation' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Spelling' => ['minor' => 0, 'major' => 0, 'critical' => 0]
            ],
            'Style' => [
                'Readability' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Text Typology' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Style Guide' => ['minor' => 0, 'major' => 0, 'critical' => 0]
            ],
            'Terminology' => [
                'Glossary' => ['minor' => 0, 'major' => 0, 'critical' => 0],
                'Authority' => ['minor' => 0, 'major' => 0, 'critical' => 0]
            ]
        ],
        'severity_totals' => ['minor' => 0, 'major' => 0, 'critical' => 0],
        'human_errors' => 0,
        'error_rate' => 0,
        'error_points' => 0 // Error Points 계산
    ];
    
    foreach ($qa_data as $row) {
        $error_group = $row['error_group'] ?? '';
        $error_subgroup = $row['error_subgroup'] ?? '';
        $severity = strtolower($row['severity'] ?? 'medium');
        $human_error = strtolower($row['human_error'] ?? '');
        $source = $row['source'] ?? '';
        
        // 디버깅: 각 행의 데이터 로깅
        error_log("QA 통계 계산 - Row: Group='{$error_group}', Subgroup='{$error_subgroup}', Severity='{$severity}', Human='{$human_error}'");
        
        // Word Count 계산 (Source 텍스트 기준)
        $stats['total_words'] += str_word_count($source);
        
        // 심각도별 카운트
        if (in_array($severity, ['minor', 'major', 'critical'])) {
            $stats['severity_totals'][$severity]++;
            error_log("심각도 카운트: {$severity} 증가");
        } else {
            error_log("심각도 매칭 실패: '{$severity}' (예상: minor, major, critical)");
        }
        
        // Human Error 카운트
        if (strpos($human_error, 'yes') !== false || strpos($human_error, 'true') !== false) {
            $stats['human_errors']++;
            error_log("Human Error 카운트 증가");
        }
        
        // 카테고리별 카운트 (개선된 로직)
        $category_matched = false;
        foreach ($stats['error_categories'] as $category => $subcategories) {
            // 정확한 매칭 또는 부분 매칭 시도
            if (stripos($error_group, $category) !== false || stripos($category, $error_group) !== false) {
                foreach ($subcategories as $subcategory => $counts) {
                    if (stripos($error_subgroup, $subcategory) !== false || stripos($subcategory, $error_subgroup) !== false) {
                        if (in_array($severity, ['minor', 'major', 'critical'])) {
                            $stats['error_categories'][$category][$subcategory][$severity]++;
                            $category_matched = true;
                            error_log("QA 통계 카운트: {$category} - {$subcategory} - {$severity} (원본: {$error_group} - {$error_subgroup})");
                        }
                        break 2;
                    }
                }
            }
        }
        
        // 매칭되지 않은 경우 기본 카테고리에 추가
        if (!$category_matched && in_array($severity, ['minor', 'major', 'critical'])) {
            // Accuracy > Mistranslation에 기본 추가
            $stats['error_categories']['Accuracy']['Mistranslation'][$severity]++;
            error_log("QA 통계 기본 카운트: Accuracy - Mistranslation - {$severity} (원본: {$error_group} - {$error_subgroup})");
        } else if (!$category_matched) {
            error_log("카테고리 매칭 실패 및 기본 추가도 실패: Group='{$error_group}', Subgroup='{$error_subgroup}', Severity='{$severity}'");
        }
    }
    
    // Error Points 계산 (QA Evaluation 시트 기준)
    $stats['error_points'] = ($stats['severity_totals']['minor'] * 1) + 
                             ($stats['severity_totals']['major'] * 5) + 
                             ($stats['severity_totals']['critical'] * 10);
    
    // 정확한 오류율 계산 (Error Points / Word Count * 100)
    if ($stats['total_words'] > 0) {
        $stats['error_rate'] = round(($stats['error_points'] / $stats['total_words']) * 100, 2);
    }
    
    // 최종 통계 결과 로깅
    error_log("QA 통계 최종 결과: " . json_encode($stats));
    
    return $stats;
}

/**
 * CSV 파일 파싱
 */
function parseCSVFile($file_path) {
    $data = [];
    $file = fopen($file_path, 'r');
    
    if (!$file) {
        throw new Exception("CSV 파일을 열 수 없습니다.");
    }
    
    // BOM 제거
    $bom = fread($file, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file);
    }
    
    // 헤더 읽기
    $headers = fgetcsv($file);
    if (!$headers) {
        throw new Exception("CSV 헤더를 읽을 수 없습니다.");
    }
    
    // 데이터 읽기
    while (($row = fgetcsv($file)) !== false) {
        // 빈 행 건너뛰기
        if (empty(array_filter($row))) {
            continue;
        }
        
        // 헤더와 데이터 컬럼 수가 다르면 건너뜀
        if (count($row) !== count($headers)) {
            error_log("컬럼 수 불일치: " . json_encode($row));
            continue;
        }
        
        // 최소 필수 필드 확인 (source와 translation/correction 중 하나)
        $row_data = array_combine($headers, $row);
        $has_source = isset($row_data['source']) && !empty($row_data['source']);
        $has_translation = isset($row_data['target']) && !empty($row_data['target']);
        $has_correction = isset($row_data['correction']) && !empty($row_data['correction']);
        
        if ($has_source && ($has_translation || $has_correction)) {
            // translator_id가 없으면 자동 생성
            if (!isset($row_data['translator_id']) || empty($row_data['translator_id'])) {
                $row_data['translator_id'] = 'AUTO_' . date('YmdHis') . '_' . rand(1000, 9999);
            }
            
            // correction이 없으면 translation으로 설정
            if (!isset($row_data['correction']) || empty($row_data['correction'])) {
                $row_data['correction'] = $row_data['target'] ?? '';
            }
            
            $data[] = $row_data;
        }
    }
    
    fclose($file);
    return $data;
}

/**
 * XLSX 파일 파싱 (간단한 구현)
 */
function parseXLSXFile($file_path) {
    // 실제 운영에서는 PhpSpreadsheet 라이브러리 사용 권장
    // 여기서는 간단한 CSV 변환으로 대체
    return parseCSVFile($file_path);
}

/**
 * AI 기반 자동 오류 분류 (QA 구조에 맞춤)
 */
function autoClassifyError($source, $correction, $error_desc = '') {
    // 디버깅 정보
    error_log("자동 분류 시작 - Source: " . substr($source, 0, 50) . ", Correction: " . substr($correction, 0, 50) . ", Error Desc: " . substr($error_desc, 0, 50));
    
    $classification = [
        'error_group' => 'Accuracy',
        'error_subgroup' => 'Mistranslation',
        'severity' => 'major'  // 소문자로 통일
    ];
    
    // 1. 번역 누락/추가 오류 검출
    if (strlen(trim($correction)) === 0) {
        $classification['error_group'] = 'Accuracy';
        $classification['error_subgroup'] = 'Untranslation';
        $classification['severity'] = 'critical';
    } elseif (strlen($correction) > strlen($source) * 1.5) {
        $classification['error_group'] = 'Accuracy';
        $classification['error_subgroup'] = 'Addition/Omission';
        $classification['severity'] = 'major';
    }
    
    // 2. 문법 오류 검출
    $grammar_patterns = [
        '/[가-힣]+[은는이가]?\s+[가-힣]+[을를]?\s+[가-힣]+[다]?/' => 'Grammar',
        '/[A-Za-z]+\s+[가-힣]+/' => 'Mixed Language'
    ];
    
    foreach ($grammar_patterns as $pattern => $subgroup) {
        if (preg_match($pattern, $correction)) {
            $classification['error_group'] = 'Language';
            $classification['error_subgroup'] = 'Grammar';
            $classification['severity'] = 'major';
            break;
        }
    }
    
    // 3. 용어 오류 검출
    $medical_terms = ['FEV1', 'COPD', 'asthma', 'bronchitis', 'pneumonia', 'diabetes', 'hypertension'];
    foreach ($medical_terms as $term) {
        if (stripos($source, $term) !== false && stripos($correction, $term) === false) {
            $classification['error_group'] = 'Terminology';
            $classification['error_subgroup'] = 'Glossary';
            $classification['severity'] = 'critical';
            break;
        }
    }
    
    // 4. 오타 검출
    $typo_patterns = [
        '/[가-힣]{2,}다[가-힣]{2,}/' => 'Spelling',
        '/[A-Za-z]{2,}[0-9]{2,}/' => 'Format'
    ];
    
    foreach ($typo_patterns as $pattern => $subgroup) {
        if (preg_match($pattern, $correction)) {
            $classification['error_group'] = 'Language';
            $classification['error_subgroup'] = 'Spelling';
            $classification['severity'] = 'minor';
            break;
        }
    }
    
    // 5. error_desc 기반 분류
    if (!empty($error_desc)) {
        $desc_lower = strtolower($error_desc);
        
        if (strpos($desc_lower, '용어') !== false || strpos($desc_lower, 'terminology') !== false) {
            $classification['error_group'] = 'Terminology';
            $classification['error_subgroup'] = 'Glossary';
            $classification['severity'] = 'critical';
        } elseif (strpos($desc_lower, '문법') !== false || strpos($desc_lower, 'grammar') !== false) {
            $classification['error_group'] = 'Language';
            $classification['error_subgroup'] = 'Grammar';
            $classification['severity'] = 'major';
        } elseif (strpos($desc_lower, '오타') !== false || strpos($desc_lower, 'spelling') !== false) {
            $classification['error_group'] = 'Language';
            $classification['error_subgroup'] = 'Spelling';
            $classification['severity'] = 'minor';
        } elseif (strpos($desc_lower, '일관성') !== false || strpos($desc_lower, 'consistency') !== false) {
            $classification['error_group'] = 'Accuracy';
            $classification['error_subgroup'] = 'Consistency';
            $classification['severity'] = 'major';
        } elseif (strpos($desc_lower, '가독성') !== false || strpos($desc_lower, 'readability') !== false) {
            $classification['error_group'] = 'Style';
            $classification['error_subgroup'] = 'Readability';
            $classification['severity'] = 'minor';
        }
    }
    
    // 최종 분류 결과 로깅
    error_log("자동 분류 결과 - Group: " . $classification['error_group'] . ", Subgroup: " . $classification['error_subgroup'] . ", Severity: " . $classification['severity']);
    
    return $classification;
}

/**
 * 통계 데이터 생성
 */
function generateStatistics($qa_data) {
    $stats = [
        'total_errors' => count($qa_data),
        'translator_stats' => [],
        'error_group_stats' => [],
        'error_subgroup_stats' => [],
        'severity_stats' => [],
        'top_errors' => []
    ];
    
    foreach ($qa_data as $row) {
        $translator_id = $row['translator_id'] ?? 'Unknown';
        $error_group = $row['error_group'] ?? 'Unknown';
        $error_subgroup = $row['error_subgroup'] ?? 'Unknown';
        $severity = $row['severity'] ?? 'Medium';
        
        // 디버깅: 일반 통계 계산 로깅
        error_log("일반 통계 계산 - Translator: {$translator_id}, Group: {$error_group}, Subgroup: {$error_subgroup}, Severity: {$severity}");
        
        // 번역가별 통계
        if (!isset($stats['translator_stats'][$translator_id])) {
            $stats['translator_stats'][$translator_id] = [
                'total' => 0,
                'critical' => 0,
                'major' => 0,
                'minor' => 0,
                'medium' => 0,
                'low' => 0
            ];
        }
        $stats['translator_stats'][$translator_id]['total']++;
        
        // 심각도 매핑 (대소문자 통일)
        $severity_lower = strtolower($severity);
        if (in_array($severity_lower, ['critical', 'major', 'minor', 'medium', 'low'])) {
            $stats['translator_stats'][$translator_id][$severity_lower]++;
        } else {
            // 기본값으로 medium 설정
            $stats['translator_stats'][$translator_id]['medium']++;
            error_log("알 수 없는 심각도: '{$severity}', 기본값 'medium'으로 설정");
        }
        
        // 오류 그룹별 통계
        if (!isset($stats['error_group_stats'][$error_group])) {
            $stats['error_group_stats'][$error_group] = 0;
        }
        $stats['error_group_stats'][$error_group]++;
        
        // 오류 서브그룹별 통계
        if (!isset($stats['error_subgroup_stats'][$error_subgroup])) {
            $stats['error_subgroup_stats'][$error_subgroup] = 0;
        }
        $stats['error_subgroup_stats'][$error_subgroup]++;
        
        // 심각도별 통계 (대소문자 통일)
        $severity_normalized = ucfirst(strtolower($severity)); // 첫 글자만 대문자
        if (!isset($stats['severity_stats'][$severity_normalized])) {
            $stats['severity_stats'][$severity_normalized] = 0;
        }
        $stats['severity_stats'][$severity_normalized]++;
    }
    
    // 상위 오류 리스트 (서브그룹 기준)
    arsort($stats['error_subgroup_stats']);
    $stats['top_errors'] = array_slice($stats['error_subgroup_stats'], 0, 10, true);
    
    // 최종 통계 결과 로깅
    error_log("일반 통계 최종 결과: " . json_encode($stats));
    
    return $stats;
}

/**
 * 메인 실행 함수 (Python 코드의 전체 로직을 PHP로 변환)
 */
function main() {
    global $DATABASE_URL, $DB_USER, $DB_PASS, $TEMPLATE_MAP;
    
    try {
        echo "=== 번역 품질 관리 시스템 (PHP 버전) ===\n";
        
        // 1. 데이터베이스 연결 (Python의 create_engine() 대체)
        echo "\n1. 데이터베이스 연결 중...\n";
        $engine = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
        
        // 2. DB에서 필터링된 레코드 조회 (Python의 pd.read_sql() 대체)
        echo "\n2. QA 데이터 조회 중...\n";
        $query = "
            SELECT translator_id, source, correction AS Correction,
                   error_desc AS 'Description of the Error',
                   CONCAT(error_group,' / ',error_subgroup) AS 'Error Category',
                   severity AS Severity
            FROM translation_qas
            WHERE flag = 'QA 시트 기록 필요'
        ";
        $df = readSql($engine, $query);
        
        // 3. 엑셀 내보내기 (Python의 df.to_excel() 대체)
        echo "\n3. QA 데이터 엑셀 내보내기 중...\n";
        if (!empty($df)) {
            $filename = toExcel($df, 'QA_Output.xlsx');
        } else {
            echo "내보낼 QA 데이터가 없습니다.\n";
        }
        
        // 4. DB에서 알림 대상 조회 (Python의 pd.read_sql() 대체)
        echo "\n4. 알림 대상 조회 중...\n";
        $notify_query = "
            SELECT t.email, q.action, q.error_group, q.error_subgroup, q.severity, q.error_count 
            FROM translation_qas q 
            JOIN translators t ON q.translator_id = t.translator_id 
            WHERE q.action IN ('교육 발송','경고 메일') AND (q.notified IS NULL OR q.notified = FALSE)
        ";
        $df_notify = readSql($engine, $notify_query);
        
        // 5. 이메일 발송 (Python의 for _, row in df_notify.iterrows() 대체)
        echo "\n5. 이메일 발송 중...\n";
        if (empty($df_notify)) {
            echo "발송할 알림이 없습니다.\n";
        } else {
            foreach ($df_notify as $row) {
                $subject = "[{$row['action']}] 번역 QA 알림";
                
                if ($row['action'] == "경고 메일") {
                    // Python의 .format() 대체
                    $body = str_replace(
                        ['{error_type}', '{severity}', '{error_count}'],
                        [
                            $row['error_group'] . ' / ' . $row['error_subgroup'],
                            $row['severity'],
                            $row['error_count']
                        ],
                        $TEMPLATE_MAP[$row['action']]
                    );
                } else {
                    $body = $TEMPLATE_MAP[$row['action']];
                }
                
                // 이메일 발송 (Python의 send_mail() 대체)
                $success = sendMail($row['email'], $subject, $body);
                
                // 발송 성공 시 notified 플래그 업데이트 (Python의 engine.execute() 대체)
                if ($success) {
                    $update_query = "
                        UPDATE translation_qas 
                        SET notified = TRUE, notified_date = NOW() 
                        WHERE translator_id = (SELECT translator_id FROM translators WHERE email = :email)
                        AND action = :action
                    ";
                    executeSql($engine, $update_query, [
                        ':email' => $row['email'],
                        ':action' => $row['action']
                    ]);
                }
            }
        }
        
        echo "\n이메일 알림 발송 완료\n";
        echo "\n모든 작업이 완료되었습니다.\n";
        
    } catch (PDOException $e) {
        echo "데이터베이스 연결 오류: " . $e->getMessage() . "\n";
        echo "데이터베이스 설정을 확인해주세요.\n";
    } catch (Exception $e) {
        echo "오류 발생: " . $e->getMessage() . "\n";
    }
}

// ===================== DB 접속 정보 명확히 선언 =====================
$DATABASE_URL = "mysql:host=meditrans.co.kr;dbname=kwonsolutions;charset=utf8mb4";
$DB_USER = "kwonsolutions";
$DB_PASS = "Meditrans@";
// ===================================================================

/**
 * 데이터베이스 테이블 생성 함수
 */
function createTables($pdo) {
    try {
        // translators 테이블 생성
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS translators (
                translator_id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL,
                join_date DATE NOT NULL,
                role ENUM('Translator', 'Reviewer', 'Manager') NOT NULL,
                status ENUM('Active', 'Inactive', 'Suspended') NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // translation_qas 테이블 생성
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS translation_qas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                translator_id VARCHAR(50),
                source TEXT,
                target TEXT,
                correction TEXT,
                error_desc TEXT,
                error_group VARCHAR(100),
                error_subgroup VARCHAR(100),
                severity ENUM('minor', 'major', 'critical', 'low', 'medium', 'high') DEFAULT 'medium',
                human_error ENUM('yes', 'no', 'true', 'false') DEFAULT 'no',
                flag VARCHAR(50),
                action VARCHAR(100),
                auto_confidence DECIMAL(3,2) DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_translator_id (translator_id),
                INDEX idx_error_group (error_group),
                INDEX idx_severity (severity),
                FOREIGN KEY (translator_id) REFERENCES translators(translator_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        error_log("데이터베이스 테이블 생성 완료");
        return true;
    } catch (PDOException $e) {
        error_log("테이블 생성 실패: " . $e->getMessage());
        return false;
    }
}

// 세션 시작
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 폼 처리 로직
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 데이터베이스 연결 테스트
        $pdo = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
        
        // 연결 테스트
        $pdo->query("SELECT 1");
        
        // 테이블 생성 확인
        createTables($pdo);
        
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'register_translator':
                    $translator_data = [
                        ':translator_id' => $_POST['translator_id'],
                        ':name' => $_POST['name'],
                        ':email' => $_POST['email'],
                        ':join_date' => $_POST['join_date'],
                        ':role' => $_POST['role'],
                        ':status' => $_POST['status']
                    ];
                    
                    if (registerTranslator($pdo, $translator_data)) {
                        $message = "번역가가 성공적으로 등록되었습니다.";
                        $message_type = 'success';
                    }
                    break;
                    
                case 'upload_excel_with_translator':
                    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
                        $selected_translator_id = $_POST['selected_translator_id'] ?? '';
                        if (empty($selected_translator_id)) {
                            $message = "번역가를 선택해주세요.";
                            $message_type = 'error';
                            break;
                        }
                        $upload_dir = __DIR__ . '/uploads/';
                        if (!is_dir($upload_dir)) {
                            if (!mkdir($upload_dir, 0755, true)) {
                                $message = "업로드 디렉토리 생성에 실패했습니다.";
                                $message_type = 'error';
                                error_log("[UPLOAD ERROR] 업로드 디렉토리 생성 실패: $upload_dir");
                                break;
                            }
                        }
                        $file_name = basename($_FILES['excel_file']['name']);
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $upload_path)) {
                            try {
                                // 파일명에 'qa'가 포함되어 있고, 헤더가 QA 형식인지 확인
                                if (stripos($file_name, 'qa') !== false) {
                                    // 먼저 파일의 헤더를 확인
                                    $test_file = fopen($upload_path, 'r');
                                    if ($test_file) {
                                        // BOM 제거
                                        $bom = fread($test_file, 3);
                                        if ($bom !== "\xEF\xBB\xBF") {
                                            rewind($test_file);
                                        }
                                        
                                        $test_headers = fgetcsv($test_file);
                                        fclose($test_file);
                                        
                                        // QA 파일인지 확인 (Source와 Translation이 있는지)
                                        $is_qa_file = false;
                                        if ($test_headers && count($test_headers) >= 2) {
                                            $has_source = in_array('Source', $test_headers);
                                            $has_translation = in_array('Translation', $test_headers) || in_array('Target', $test_headers);
                                            $is_qa_file = $has_source && $has_translation;
                                        }
                                    }
                                    
                                    if ($is_qa_file) {
                                        // QA Details 시트 파싱
                                        $qa_data = parseQADetailsSheet($upload_path);
                                        
                                        // 선택된 번역가 ID로 모든 행 설정
                                        foreach ($qa_data as &$row) {
                                            $row['translator_id'] = $selected_translator_id;
                                        }
                                        
                                        // AI 자동 분류 적용
                                        $auto_classified_count = 0;
                                        foreach ($qa_data as &$row) {
                                            $classification = autoClassifyError(
                                                $row['source'] ?? '',
                                                $row['target'] ?? '',
                                                $row['error_desc'] ?? ''
                                            );
                                            
                                            $row['error_group'] = !empty($row['error_group']) ? $row['error_group'] : $classification['error_group'];
                                            $row['error_subgroup'] = !empty($row['error_subgroup']) ? $row['error_subgroup'] : $classification['error_subgroup'];
                                            $row['severity'] = !empty($row['severity']) ? $row['severity'] : $classification['severity'];
                                            
                                            $auto_classified_count++;
                                            // DB 저장
                                            saveQARowToDB($pdo, $row);
                                        }
                                        
                                        // QA 통계 계산
                                        $qa_stats = calculateQAStatistics($qa_data);
                                        $stats = generateStatistics($qa_data);
                                        
                                        // 세션에 결과 저장
                                        $_SESSION['qa_analysis_result'] = [
                                            'qa_data' => $qa_data,
                                            'statistics' => $stats,
                                            'qa_statistics' => $qa_stats,
                                            'upload_time' => date('Y-m-d H:i:s'),
                                            'file_type' => 'qa_details',
                                            'selected_translator_id' => $selected_translator_id
                                        ];
                                        
                                        // 업로드 후 DB에 데이터가 저장되었는지 확인
                                        $saved_count = $pdo->query("SELECT COUNT(*) FROM translation_qas WHERE translator_id = '{$selected_translator_id}'")->fetchColumn();
                                        if ($saved_count > 0) {
                                            $message = "선택된 번역가({$selected_translator_id})로 QA 파일 분석이 완료되었습니다. 총 {$qa_stats['total_segments']}개 세그먼트, {$qa_stats['total_words']}단어, 오류율: {$qa_stats['error_rate']}% (DB 저장: {$saved_count}개)";
                                            $message_type = 'success';
                                        } else {
                                            $message = "파일 분석은 완료되었지만 DB에 데이터가 저장되지 않았습니다. 데이터베이스 연결을 확인해주세요.";
                                            $message_type = 'error';
                                        }
                                    }
                                } else {
                                    // 일반 엑셀 파일 파싱
                                    $qa_data = parseExcelFile($upload_path);
                                    
                                    // 선택된 번역가 ID로 모든 행 설정
                                    foreach ($qa_data as &$row) {
                                        $row['translator_id'] = $selected_translator_id;
                                    }
                                    
                                    // AI 자동 분류 적용
                                    $auto_classified_count = 0;
                                    foreach ($qa_data as &$row) {
                                        $classification = autoClassifyError(
                                            $row['source'] ?? '',
                                            $row['correction'] ?? '',
                                            $row['error_desc'] ?? ''
                                        );
                                        
                                        $row['error_group'] = !empty($row['error_group']) ? $row['error_group'] : $classification['error_group'];
                                        $row['error_subgroup'] = !empty($row['error_subgroup']) ? $row['error_subgroup'] : $classification['error_subgroup'];
                                        $row['severity'] = !empty($row['severity']) ? $row['severity'] : $classification['severity'];
                                        
                                        $auto_classified_count++;
                                        // DB 저장
                                        saveQARowToDB($pdo, $row);
                                    }
                                    
                                    // 통계 생성
                                    $stats = generateStatistics($qa_data);
                                    
                                    // 세션에 결과 저장
                                    $_SESSION['qa_analysis_result'] = [
                                        'qa_data' => $qa_data,
                                        'statistics' => $stats,
                                        'upload_time' => date('Y-m-d H:i:s'),
                                        'file_type' => 'general',
                                        'selected_translator_id' => $selected_translator_id
                                    ];
                                    
                                    // 업로드 후 DB에 데이터가 저장되었는지 확인
                                    $saved_count = $pdo->query("SELECT COUNT(*) FROM translation_qas WHERE translator_id = '{$selected_translator_id}'")->fetchColumn();
                                    if ($saved_count > 0) {
                                        $message = "선택된 번역가({$selected_translator_id})로 엑셀 파일 분석이 완료되었습니다. 총 {$stats['total_errors']}개의 오류가 분석되었습니다. (DB 저장: {$saved_count}개)";
                                        $message_type = 'success';
                                    } else {
                                        $message = "파일 분석은 완료되었지만 DB에 데이터가 저장되지 않았습니다. 데이터베이스 연결을 확인해주세요.";
                                        $message_type = 'error';
                                    }
                                }
                                
                            } catch (Exception $e) {
                                $message = "파일 분석 중 오류가 발생했습니다: " . $e->getMessage();
                                $message_type = 'error';
                            }
                        } else {
                            $message = "파일 업로드에 실패했습니다.";
                            $message_type = 'error';
                        }
                    } else {
                        if (isset($_FILES['excel_file'])) {
                            switch ($_FILES['excel_file']['error']) {
                                case UPLOAD_ERR_INI_SIZE:
                                    $message = "파일 크기가 서버 설정을 초과했습니다.";
                                    break;
                                case UPLOAD_ERR_FORM_SIZE:
                                    $message = "파일 크기가 HTML 폼에서 지정한 최대 크기를 초과했습니다.";
                                    break;
                                case UPLOAD_ERR_PARTIAL:
                                    $message = "파일이 일부만 업로드되었습니다.";
                                    break;
                                case UPLOAD_ERR_NO_FILE:
                                    $message = "파일을 선택해주세요.";
                                    break;
                                default:
                                    $message = "파일 업로드 중 오류가 발생했습니다. (에러코드: " . $_FILES['excel_file']['error'] . ")";
                            }
                            error_log("[UPLOAD ERROR] PHP 업로드 에러코드: " . $_FILES['excel_file']['error']);
                        } else {
                            $message = "파일을 선택해주세요.";
                        }
                        $message_type = 'error';
                    }
                    break;
                    
                case 'upload_excel':
                    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = __DIR__ . '/uploads/';
                        if (!is_dir($upload_dir)) {
                            if (!mkdir($upload_dir, 0755, true)) {
                                $message = "업로드 디렉토리 생성에 실패했습니다.";
                                $message_type = 'error';
                                error_log("[UPLOAD ERROR] 업로드 디렉토리 생성 실패: $upload_dir");
                                break;
                            }
                        }
                        $file_name = basename($_FILES['excel_file']['name']);
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $upload_path)) {
                            try {
                                // 파일명에 'qa'가 포함되어 있고, 헤더가 QA 형식인지 확인
                                if (stripos($file_name, 'qa') !== false) {
                                    // 먼저 파일의 헤더를 확인
                                    $test_file = fopen($upload_path, 'r');
                                    if ($test_file) {
                                        // BOM 제거
                                        $bom = fread($test_file, 3);
                                        if ($bom !== "\xEF\xBB\xBF") {
                                            rewind($test_file);
                                        }
                                        
                                        $test_headers = fgetcsv($test_file);
                                        fclose($test_file);
                                        
                                        // QA 파일인지 확인 (Source와 Translation이 있는지)
                                        $is_qa_file = false;
                                        if ($test_headers && count($test_headers) >= 2) {
                                            $has_source = in_array('Source', $test_headers);
                                            $has_translation = in_array('Translation', $test_headers) || in_array('Target', $test_headers);
                                            $is_qa_file = $has_source && $has_translation;
                                        }
                                    }
                                    
                                    if ($is_qa_file) {
                                    // QA Details 시트 파싱
                                    $qa_data = parseQADetailsSheet($upload_path);
                                    
                                    // AI 자동 분류 적용 (모든 행에 대해)
                                    $auto_classified_count = 0;
                                    foreach ($qa_data as &$row) {
                                        // 분류 전 상태 로깅
                                        error_log("분류 전 - Source: " . substr($row['source'] ?? '', 0, 50) . ", Group: " . ($row['error_group'] ?? '빈값') . ", Subgroup: " . ($row['error_subgroup'] ?? '빈값') . ", Severity: " . ($row['severity'] ?? '빈값'));
                                        
                                        $classification = autoClassifyError(
                                            $row['source'] ?? '',
                                            $row['target'] ?? '',
                                            $row['error_desc'] ?? ''
                                        );
                                        
                                        // 기존 값이 있으면 유지, 없으면 자동 분류 적용
                                        $row['error_group'] = !empty($row['error_group']) ? $row['error_group'] : $classification['error_group'];
                                        $row['error_subgroup'] = !empty($row['error_subgroup']) ? $row['error_subgroup'] : $classification['error_subgroup'];
                                        $row['severity'] = !empty($row['severity']) ? $row['severity'] : $classification['severity'];
                                        
                                        // 분류 후 상태 로깅
                                        error_log("분류 후 - Group: " . $row['error_group'] . ", Subgroup: " . $row['error_subgroup'] . ", Severity: " . $row['severity']);
                                        
                                        $auto_classified_count++;
                                        // DB 저장
                                        saveQARowToDB($pdo, $row);
                                    }
                                    
                                    error_log("QA 파일 자동 분류 완료 - 총 " . count($qa_data) . "개 중 " . $auto_classified_count . "개 분류됨");
                                    
                                    // QA 통계 계산
                                    $qa_stats = calculateQAStatistics($qa_data);
                                    
                                    // 디버깅: QA 통계 결과 로깅
                                    error_log("QA 통계 결과: " . json_encode($qa_stats));
                                    
                                    // 기존 통계도 생성
                                    $stats = generateStatistics($qa_data);
                                    
                                    // 세션에 결과 저장
                                    $_SESSION['qa_analysis_result'] = [
                                        'qa_data' => $qa_data,
                                        'statistics' => $stats,
                                        'qa_statistics' => $qa_stats,
                                        'upload_time' => date('Y-m-d H:i:s'),
                                        'file_type' => 'qa_details'
                                    ];
                                    
                                    // 업로드 후 DB에 데이터가 저장되었는지 확인
                                    $total_saved = $pdo->query("SELECT COUNT(*) FROM translation_qas")->fetchColumn();
                                    if ($total_saved > 0) {
                                        $message = "QA 엑셀 파일 분석이 완료되었습니다. 총 {$qa_stats['total_segments']}개 세그먼트, {$qa_stats['total_words']}단어, 오류율: {$qa_stats['error_rate']}% (DB 저장: {$total_saved}개)";
                                        $message_type = 'success';
                                    } else {
                                        $message = "파일 분석은 완료되었지만 DB에 데이터가 저장되지 않았습니다. 데이터베이스 연결을 확인해주세요.";
                                        $message_type = 'error';
                                    }
                                    }
                                } else {
                                    // 일반 엑셀 파일 파싱 (QA 파일이 아니거나 헤더가 맞지 않는 경우)
                                    $qa_data = parseExcelFile($upload_path);
                                    
                                    // AI 자동 분류 적용 (모든 행에 대해)
                                    $auto_classified_count = 0;
                                    foreach ($qa_data as &$row) {
                                        $classification = autoClassifyError(
                                            $row['source'] ?? '',
                                            $row['correction'] ?? '',
                                            $row['error_desc'] ?? ''
                                        );
                                        
                                        // 기존 값이 있으면 유지, 없으면 자동 분류 적용
                                        $row['error_group'] = !empty($row['error_group']) ? $row['error_group'] : $classification['error_group'];
                                        $row['error_subgroup'] = !empty($row['error_subgroup']) ? $row['error_subgroup'] : $classification['error_subgroup'];
                                        $row['severity'] = !empty($row['severity']) ? $row['severity'] : $classification['severity'];
                                        
                                        $auto_classified_count++;
                                        // DB 저장
                                        saveQARowToDB($pdo, $row);
                                        
                                        // 디버깅 정보
                                        error_log("일반 파일 자동 분류 - Source: " . substr($row['source'] ?? '', 0, 50) . ", 분류: " . $classification['error_group'] . "/" . $classification['error_subgroup'] . "/" . $classification['severity']);
                                    }
                                    
                                    error_log("일반 파일 자동 분류 완료 - 총 " . count($qa_data) . "개 중 " . $auto_classified_count . "개 분류됨");
                                    
                                    // 통계 생성
                                    $stats = generateStatistics($qa_data);
                                    
                                    // 세션에 결과 저장
                                    $_SESSION['qa_analysis_result'] = [
                                        'qa_data' => $qa_data,
                                        'statistics' => $stats,
                                        'upload_time' => date('Y-m-d H:i:s'),
                                        'file_type' => 'general'
                                    ];
                                    
                                    // 업로드 후 DB에 데이터가 저장되었는지 확인
                                    $total_saved = $pdo->query("SELECT COUNT(*) FROM translation_qas")->fetchColumn();
                                    if ($total_saved > 0) {
                                        $message = "엑셀 파일 분석이 완료되었습니다. 총 {$stats['total_errors']}개의 오류가 분석되었습니다. (DB 저장: {$total_saved}개)";
                                        $message_type = 'success';
                                    } else {
                                        $message = "파일 분석은 완료되었지만 DB에 데이터가 저장되지 않았습니다. 데이터베이스 연결을 확인해주세요.";
                                        $message_type = 'error';
                                    }
                                }
                                
                            } catch (Exception $e) {
                                $message = "파일 분석 중 오류가 발생했습니다: " . $e->getMessage();
                                $message_type = 'error';
                                error_log("[UPLOAD ERROR] 파일 분석/DB 저장 오류: " . $e->getMessage());
                            }
                        } else {
                            $message = "파일 업로드에 실패했습니다.";
                            $message_type = 'error';
                            error_log("[UPLOAD ERROR] move_uploaded_file 실패. tmp: " . $_FILES['excel_file']['tmp_name'] . ", dest: $upload_path, error code: " . $_FILES['excel_file']['error']);
                            if (!is_writable($upload_dir)) {
                                error_log("[UPLOAD ERROR] 업로드 폴더에 쓰기 권한 없음: $upload_dir");
                            }
                            if (!file_exists($_FILES['excel_file']['tmp_name'])) {
                                error_log("[UPLOAD ERROR] 임시 파일이 존재하지 않음: " . $_FILES['excel_file']['tmp_name']);
                            }
                        }
                    } else {
                        if (isset($_FILES['excel_file'])) {
                            switch ($_FILES['excel_file']['error']) {
                                case UPLOAD_ERR_INI_SIZE:
                                    $message = "파일 크기가 서버 설정을 초과했습니다.";
                                    break;
                                case UPLOAD_ERR_FORM_SIZE:
                                    $message = "파일 크기가 HTML 폼에서 지정한 최대 크기를 초과했습니다.";
                                    break;
                                case UPLOAD_ERR_PARTIAL:
                                    $message = "파일이 일부만 업로드되었습니다.";
                                    break;
                                case UPLOAD_ERR_NO_FILE:
                                    $message = "파일을 선택해주세요.";
                                    break;
                                default:
                                    $message = "파일 업로드 중 오류가 발생했습니다. (에러코드: " . $_FILES['excel_file']['error'] . ")";
                            }
                            error_log("[UPLOAD ERROR] PHP 업로드 에러코드: " . $_FILES['excel_file']['error']);
                        } else {
                            $message = "파일을 선택해주세요.";
                        }
                        $message_type = 'error';
                    }
                    break;
                case 'delete_translator':
                    $del_id = $_POST['translator_id'] ?? '';
                    if ($del_id) {
                        $stmt = $pdo->prepare("DELETE FROM translators WHERE translator_id = :id");
                        $stmt->execute([':id' => $del_id]);
                        $message = "번역가가 삭제되었습니다.";
                        $message_type = 'success';
                    }
                    break;
                case 'edit_translator':
                    $edit_id = $_POST['translator_id'] ?? '';
                    $edit_role = $_POST['role'] ?? '';
                    $edit_status = $_POST['status'] ?? '';
                    if ($edit_id && $edit_role && $edit_status) {
                        $stmt = $pdo->prepare("UPDATE translators SET role = :role, status = :status WHERE translator_id = :id");
                        $stmt->execute([':role' => $edit_role, ':status' => $edit_status, ':id' => $edit_id]);
                        $message = "번역가 정보가 수정되었습니다.";
                        $message_type = 'success';
                    }
                    break;
                case 'delete_translator_multi':
                    $ids = $_POST['translator_ids'] ?? [];
                    if ($ids && is_array($ids)) {
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $stmt = $pdo->prepare("DELETE FROM translators WHERE translator_id IN ($in)");
                        $stmt->execute($ids);
                        $message = "선택한 번역가가 삭제되었습니다.";
                        $message_type = 'success';
                    }
                    break;
            }
        }
    } catch (PDOException $e) {
        $message = "데이터베이스 연결 실패: " . $e->getMessage() . " - 데이터베이스 설정을 확인해주세요.";
        $message_type = 'error';
    } catch (Exception $e) {
        $message = "오류 발생: " . $e->getMessage();
        $message_type = 'error';
    }
}

// 스크립트 실행
if (php_sapi_name() === 'cli'):
    main();
else:
    // 웹 브라우저에서 실행 시
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>번역 품질 관리 시스템</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #e8f5e8 0%, #d4edda 100%);
                min-height: 100vh;
                padding: 20px;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                overflow: hidden;
            }
            
            .header {
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .header h1 {
                font-size: 2.5em;
                margin-bottom: 10px;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            }
            
            .header p {
                font-size: 1.1em;
                opacity: 0.9;
            }
            
            .content {
                padding: 40px;
            }
            
            .nav-tabs {
                display: flex;
                border-bottom: 2px solid #e9ecef;
                margin-bottom: 30px;
            }
            
            .nav-tab {
                padding: 15px 25px;
                background: #f8f9fa;
                border: none;
                cursor: pointer;
                font-size: 1em;
                font-weight: 600;
                transition: all 0.3s ease;
                border-radius: 8px 8px 0 0;
                margin-right: 5px;
            }
            
            .nav-tab.active {
                background: #28a745;
                color: white;
            }
            
            .nav-tab:hover {
                background: #20c997;
                color: white;
            }
            
            .tab-content {
                display: none;
            }
            
            .tab-content.active {
                display: block;
            }
            
            .form-container {
                background: #f8f9fa;
                padding: 30px;
                border-radius: 10px;
                margin-bottom: 20px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #495057;
            }
            
            .form-control {
                width: 100%;
                padding: 12px;
                border: 2px solid #e9ecef;
                border-radius: 8px;
                font-size: 1em;
                transition: border-color 0.3s ease;
            }
            
            .form-control:focus {
                outline: none;
                border-color: #28a745;
                box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
            }
            
            .btn {
                display: inline-block;
                background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                color: white;
                padding: 12px 25px;
                text-decoration: none;
                border-radius: 25px;
                font-weight: 600;
                transition: all 0.3s ease;
                border: none;
                cursor: pointer;
                font-size: 1em;
            }
            
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            }
            
            .btn-secondary {
                background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            }
            
            .message {
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-weight: 600;
            }
            
            .message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            
            .info-box {
                background: #f8f9fa;
                border-left: 5px solid #28a745;
                padding: 20px;
                margin-bottom: 30px;
                border-radius: 8px;
            }
            
            .info-box h3 {
                color: #28a745;
                margin-bottom: 15px;
                font-size: 1.3em;
            }
            
            .info-box p {
                color: #6c757d;
                line-height: 1.6;
                margin-bottom: 10px;
            }
            
            .status-badge {
                display: inline-block;
                background: #28a745;
                color: white;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 0.9em;
                font-weight: 600;
            }
            
            @media (max-width: 768px) {
                .header h1 {
                    font-size: 2em;
                }
                
                .content {
                    padding: 20px;
                }
                
                .nav-tabs {
                    flex-direction: column;
                }
                
                .nav-tab {
                    border-radius: 8px;
                    margin-bottom: 5px;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🌿 번역 품질 관리 시스템(테스트)</h1>
                <p>메디트랜스 QA 관리 플랫폼</p>
            </div>
            
            <div class="content">
                <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
                <?php endif; ?>
                
                <div class="info-box">
                    <h3>📋 시스템 정보</h3>
                    <p><strong>버전:</strong> PHP 7.4+</p>
                    <p><strong>데이터베이스:</strong> MySQL</p>
                    <p><strong>상태:</strong> <span class="status-badge">정상 운영</span></p>
                </div>
                
                <div class="nav-tabs">
                    <button class="nav-tab active" onclick="showTab('translator')">👥 번역가 등록</button>
                    <button class="nav-tab" onclick="showTab('translator_list')">📋 번역가 목록</button>
                    <button class="nav-tab" onclick="showTab('upload')">📋 파일 형식 안내</button>
                    <button class="nav-tab" onclick="showTab('dashboard')">📈 대시보드</button>
                    <button class="nav-tab" onclick="showTab('info')"> 시스템 정보</button>
                </div>
                
                <!-- 번역가 등록 폼 -->
                <div id="translator" class="tab-content active">
                    <div class="form-container">
                        <h3>번역가 등록</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_translator">
                            
                            <div class="form-group">
                                <label for="translator_id">번역가 ID *</label>
                                <input type="text" id="translator_id" name="translator_id" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="name">이름 *</label>
                                <input type="text" id="name" name="name" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">이메일 *</label>
                                <input type="email" id="email" name="email" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="join_date">가입일 *</label>
                                <input type="date" id="join_date" name="join_date" class="form-control" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="role">역할 *</label>
                                <select id="role" name="role" class="form-control" required>
                                    <option value="">선택하세요</option>
                                    <option value="Translator">번역가</option>
                                    <option value="Reviewer">검토자</option>
                                    <option value="Manager">관리자</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="status">상태 *</label>
                                <select id="status" name="status" class="form-control" required>
                                    <option value="">선택하세요</option>
                                    <option value="Active">활성</option>
                                    <option value="Inactive">비활성</option>
                                    <option value="Suspended">정지</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn">등록하기</button>
                        </form>
                    </div>
                </div>
                
                <!-- 번역가 목록 -->
                <div id="translator_list" class="tab-content">
                    <div class="form-container">
                        <h3>📋 번역가 목록</h3>
                        
                        <?php
                        try {
                            $pdo = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
                            $translators = getTranslators($pdo);
                        } catch (Exception $e) {
                            $translators = [];
                        }
                        ?>
                        
                        <?php if (!empty($translators)): ?>
                            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                                <div id="translator_toolbar" style="display: flex; gap: 10px; align-items: center; margin-bottom: 16px;">
                                  <button class="btn btn-danger" id="deleteSelectedBtn" disabled>선택 삭제</button>
                                  <button class="btn btn-secondary" id="editSelectedBtn" disabled>선택 수정</button>
                                  <span id="selectedCount" style="color: #888; font-size: 0.95em;"></span>
                                </div>
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><input type="checkbox" id="selectAllTranslators"></th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6;">번역가 ID</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6;">이름</th>
                                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6;">이메일</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">가입일</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">역할</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">상태</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($translators as $translator): ?>
                                            <tr>
                                                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                                                    <input type="checkbox" class="translator-checkbox" value="<?php echo htmlspecialchars($translator['translator_id']); ?>" data-role="<?php echo htmlspecialchars($translator['role']); ?>" data-status="<?php echo htmlspecialchars($translator['status']); ?>">
                                                </td>
                                                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;"><strong><?php echo htmlspecialchars($translator['translator_id']); ?></strong></td>
                                                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($translator['name']); ?></td>
                                                <td style="padding: 12px; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($translator['email']); ?></td>
                                                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;"><?php echo htmlspecialchars($translator['join_date']); ?></td>
                                                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                                                    <?php 
                                                    $role_badge_color = '';
                                                    switch($translator['role']) {
                                                        case 'Translator':
                                                            $role_badge_color = '#28a745';
                                                            break;
                                                        case 'Reviewer':
                                                            $role_badge_color = '#fd7e14';
                                                            break;
                                                        case 'Manager':
                                                            $role_badge_color = '#dc3545';
                                                            break;
                                                        default:
                                                            $role_badge_color = '#6c757d';
                                                    }
                                                    ?>
                                                    <span style="background: <?php echo $role_badge_color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 600;">
                                                        <?php echo htmlspecialchars($translator['role']); ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6;">
                                                    <?php 
                                                    $status_badge_color = '';
                                                    switch($translator['status']) {
                                                        case 'Active':
                                                            $status_badge_color = '#28a745';
                                                            break;
                                                        case 'Inactive':
                                                            $status_badge_color = '#6c757d';
                                                            break;
                                                        case 'Suspended':
                                                            $status_badge_color = '#dc3545';
                                                            break;
                                                        default:
                                                            $status_badge_color = '#6c757d';
                                                    }
                                                    ?>
                                                    <span style="background: <?php echo $status_badge_color; ?>; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em; font-weight: 600;">
                                                        <?php echo htmlspecialchars($translator['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top: 20px; text-align: center;">
                                <p style="color: #6c757d; font-size: 0.9em;">총 <?php echo count($translators); ?>명의 번역가가 등록되어 있습니다.</p>
                                <button class="btn btn-secondary" onclick="exportTranslatorsToCSV()">📊 번역가 목록 내보내기</button>
                            </div>
                            
                            <!-- 선택된 번역가로 파일 업로드 -->
                            <div style="margin-top: 30px; background: #f8f9fa; padding: 20px; border-radius: 8px;">
                                <h4>📁 선택된 번역가로 파일 업로드</h4>
                                <p style="color: #6c757d; margin-bottom: 20px;">번역가를 선택하고 엑셀 파일을 업로드하면 해당 번역가로 자동 분류됩니다.</p>
                                
                                <form method="POST" enctype="multipart/form-data" id="translatorUploadForm">
                                    <input type="hidden" name="action" value="upload_excel_with_translator">
                                    
                                    <div style="margin-bottom: 15px;">
                                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">
                                            선택된 번역가: <span id="selectedTranslatorName" style="color: #dc3545;">번역가를 선택해주세요</span>
                                        </label>
                                        <input type="hidden" name="selected_translator_id" id="selectedTranslatorId" value="">
                                    </div>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <label for="translator_excel_file" style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057;">엑셀 파일 선택 *</label>
                                        <input type="file" id="translator_excel_file" name="excel_file" class="form-control" accept=".xlsx,.csv" required>
                                        <small class="form-text text-muted">CSV 또는 XLSX 파일을 선택하세요.</small>
                                    </div>
                                    
                                    <button type="submit" class="btn" id="uploadBtn" disabled>🤖 선택된 번역가로 분석 시작</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 50px;">
                                <h4>📋 등록된 번역가가 없습니다</h4>
                                <p>먼저 "번역가 등록" 탭에서 번역가를 등록해주세요.</p>
                                <button class="btn" onclick="showTab('translator')">번역가 등록하기</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 파일 형식 안내 -->
                <div id="upload" class="tab-content">
                    <div class="form-container">
                        <h3>📋 파일 형식 안내</h3>
                        <p>번역가 목록 탭에서 번역가를 선택하고 파일을 업로드하면 AI가 자동으로 분석합니다.</p>
                        
                        <div style="margin-top: 30px;">
                            <h4>📋 파일 형식 안내</h4>
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                                <h5>🔍 QA 파일 (파일명에 'qa' 포함)</h5>
                                <p><strong>QA Details 시트 헤더 (최소 필수):</strong></p>
                                <ul>
                                    <li><code>Source</code> - 원문 (필수)</li>
                                    <li><code>Translation</code> - 번역문 (필수)</li>
                                    <li><code>Segment/Line #</code> - 세그먼트 번호 (선택)</li>
                                    <li><code>Back Translation</code> - 역번역 (선택)</li>
                                    <li><code>Description of Error</code> - 오류 설명 (선택)</li>
                                    <li><code>Error Category (대분류)</code> - 오류 대분류 (선택, AI 자동 분류)</li>
                                    <li><code>Error Category (소분류)</code> - 오류 소분류 (선택, AI 자동 분류)</li>
                                    <li><code>Human Error 여부</code> - Human Error yes/no (선택)</li>
                                    <li><code>Severity</code> - Minor/Major/Critical (선택, AI 자동 분류)</li>
                                </ul>
                                <p><em>필수: Source, Translation만 있으면 됩니다. 나머지는 AI가 자동으로 분류합니다.</em></p>
                                <p><em>지원 헤더 변형: Target, Error Description, Category, Subcategory 등</em></p>
                                
                                <h5>📊 일반 엑셀 파일</h5>
                                <p><strong>필수 컬럼:</strong></p>
                                <ul>
                                    <li><code>source</code> - 원문 (필수)</li>
                                    <li><code>translation</code> 또는 <code>correction</code> - 번역문/수정문 (필수)</li>
                                </ul>
                                <p><strong>선택 컬럼:</strong></p>
                                <ul>
                                    <li><code>translator_id</code> - 번역가 ID (없으면 자동 생성)</li>
                                    <li><code>error_desc</code> - 오류 설명</li>
                                    <li><code>error_group</code> - 오류 대분류</li>
                                    <li><code>error_subgroup</code> - 오류 소분류</li>
                                    <li><code>severity</code> - 심각도</li>
                                </ul>
                                <p><em>선택 컬럼이 비어있으면 AI가 자동으로 분류합니다.</em></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 대시보드 -->
                <div id="dashboard" class="tab-content">
                    <div class="form-container">
                        <h3>📈 QA 대시보드</h3>
                        <?php
                        try {
                            $pdo = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
                            // 전체 오류 수
                            $total_errors = $pdo->query("SELECT COUNT(*) FROM translation_qas")->fetchColumn();
                            // 번역가 수
                            $translator_count = $pdo->query("SELECT COUNT(DISTINCT translator_id) FROM translation_qas")->fetchColumn();
                            // 오류 대분류별 집계
                            $error_group_stats = [];
                            foreach ($pdo->query("SELECT error_group, COUNT(*) as cnt FROM translation_qas GROUP BY error_group") as $row) {
                                $error_group_stats[$row['error_group'] ?: '미분류'] = $row['cnt'];
                            }
                            // 심각도별 집계
                            $severity_stats = [];
                            foreach ($pdo->query("SELECT severity, COUNT(*) as cnt FROM translation_qas GROUP BY severity") as $row) {
                                $severity_stats[$row['severity'] ?: '미분류'] = $row['cnt'];
                            }
                            // 오류율(예시: 오류 수 / 전체 row 수)
                            $total_rows = $pdo->query("SELECT COUNT(*) FROM translation_qas")->fetchColumn();
                            $error_rate = $total_rows > 0 ? round($total_errors / $total_rows * 100, 2) : 0;
                        } catch (Exception $e) {
                            echo '<div class="message error">DB 통계 조회 오류: ' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                        ?>
                        <div style="margin-bottom: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                            <div style="background: #e3f2fd; padding: 20px; border-radius: 8px; text-align: center;">
                                <h4 style="color: #1976d2; margin: 0;">총 오류 수</h4>
                                <p style="font-size: 2em; font-weight: bold; margin: 10px 0; color: #1976d2;"><?php echo $total_errors; ?></p>
                            </div>
                            <div style="background: #fff3e0; padding: 20px; border-radius: 8px; text-align: center;">
                                <h4 style="color: #f57c00; margin: 0;">번역가 수</h4>
                                <p style="font-size: 2em; font-weight: bold; margin: 10px 0; color: #f57c00;"><?php echo $translator_count; ?></p>
                            </div>
                            <div style="background: #fce4ec; padding: 20px; border-radius: 8px; text-align: center;">
                                <h4 style="color: #c2185b; margin: 0;">오류율</h4>
                                <p style="font-size: 2em; font-weight: bold; margin: 10px 0; color: #c2185b;"><?php echo $error_rate; ?>%</p>
                            </div>
                        </div>
                        <div style="margin-bottom: 30px;">
                            <h4>📊 오류 대분류별 분포</h4>
                            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                                <?php foreach ($error_group_stats as $group => $count): ?>
                                    <?php $percentage = $total_errors > 0 ? round(($count / $total_errors) * 100, 1) : 0; ?>
                                    <div style="margin: 10px 0;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span><strong><?php echo htmlspecialchars($group); ?></strong></span>
                                            <span><?php echo $count; ?>개 (<?php echo $percentage; ?>%)</span>
                                        </div>
                                        <div style="background: #e9ecef; height: 20px; border-radius: 10px; overflow: hidden;">
                                            <div style="background: #28a745; height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="margin-bottom: 30px;">
                            <h4>⚠️ 심각도별 분포</h4>
                            <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef;">
                                <?php $severity_colors = [
                                    'Critical' => '#dc3545',
                                    'High' => '#fd7e14',
                                    'Medium' => '#ffc107',
                                    'Low' => '#28a745',
                                    'Major' => '#fd7e14',
                                    'Minor' => '#28a745',
                                    '미분류' => '#6c757d'
                                ]; ?>
                                <?php foreach ($severity_stats as $severity => $count): ?>
                                    <?php $percentage = $total_errors > 0 ? round(($count / $total_errors) * 100, 1) : 0; ?>
                                    <div style="margin: 10px 0;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                            <span><strong><?php echo htmlspecialchars($severity); ?></strong></span>
                                            <span><?php echo $count; ?>개 (<?php echo $percentage; ?>%)</span>
                                        </div>
                                        <div style="background: #e9ecef; height: 20px; border-radius: 10px; overflow: hidden;">
                                            <div style="background: <?php echo $severity_colors[$severity] ?? '#6c757d'; ?>; height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.3s ease;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 시스템 정보 -->
                <div id="info" class="tab-content">
                    <div class="form-container">
                        <h3>🌿 번역 품질 관리 시스템 정보</h3>
                        
                        <div style="margin-bottom: 30px;">
                            <h4>📋 주요 기능</h4>
                            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                                <h5>🔍 파일명 검사 & 업로드</h5>
                                <ul>
                                    <li>업로드된 파일명에 qa(대소문자 무관) 포함 여부를 자동 판단</li>
                                    <li>QA Details 시트(A1~I1 헤더) 구조 검증 후 파싱</li>
                                </ul>
                                
                                <h5>📊 엑셀 파싱</h5>
                                <ul>
                                    <li>PhpSpreadsheet(또는 CSV 파서)로 QA Details 시트 데이터 추출</li>
                                    <li>필수 컬럼: Segment/Line #, Source, Translation, Back Translation, Description of Error</li>
                                    <li>AI 자동 채움 대상: Error Category(대분류/소분류), Severity, Human Error 여부</li>
                                </ul>
                                
                                <h5>🤖 오류 자동 분류</h5>
                                <ul>
                                    <li>룰 기반 패턴 매칭(문법·용어·오타 등)</li>
                                    <li>AI/머신러닝 모델로 컨텍스트 기반 보완</li>
                                    <li>분류 결과: error_group, error_subgroup, severity 자동 할당</li>
                                </ul>
                                
                                <h5>💾 DB 저장 & 통계 집계</h5>
                                <ul>
                                    <li>정제된 QA 레코드를 데이터베이스에 저장</li>
                                    <li>번역가별·오류 대분류·소분류·심각도별 집계</li>
                                    <li>총 오류 건수, 오류율(총 오류 / Word Count × 100), Human Error 비율</li>
                                </ul>
                                
                                <h5>📈 실시간 대시보드 시각화</h5>
                                <ul>
                                    <li>핵심 지표 카드: 총 오류 수, 번역가 수, 오류율, Human Error 건수 등</li>
                                    <li>오류 대분류 파이 차트, 심각도별 바 차트, 상위 오류 리스트 테이블</li>
                                    <li>번역가별 오류 현황 표</li>
                                    <li>CSV 내보내기·인쇄 기능 포함</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 30px;">
                            <h4>🔄 워크플로우</h4>
                            <div style="background: #e8f5e8; padding: 20px; border-radius: 8px;">
                                <ol style="margin: 0; padding-left: 20px;">
                                    <li><strong>사용자</strong> → 웹 UI에서 qa 엑셀 업로드</li>
                                    <li><strong>시스템</strong> → 파일명 검사 → 시트·헤더 유효성 검증</li>
                                    <li><strong>파싱</strong> → 비어 있는 오류 컬럼 AI/룰로 자동 채움</li>
                                    <li><strong>DB 저장</strong> → 통계 집계 → 대시보드 렌더링</li>
                                    <li><strong>사용자</strong>는 즉시 웹에서 결과 확인 및 다운로드</li>
                                </ol>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 30px;">
                            <h4>⚙️ 기술 정보</h4>
                            <div style="background: #fff3e0; padding: 20px; border-radius: 8px;">
                                <p><strong>CLI 실행 명령:</strong> <code>php <?php echo basename(__FILE__); ?></code></p>
                                <p><strong>웹 실행:</strong> 브라우저에서 직접 접근하여 실시간 분석 가능</p>
                                <p><strong>지원 파일 형식:</strong> CSV, XLSX (QA 파일은 파일명에 'qa' 포함)</p>
                                <p><strong>데이터베이스:</strong> MySQL/MariaDB (qa_system)</p>
                                <p><strong>AI 분류:</strong> 룰 기반 + 컨텍스트 분석</p>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button class="btn" onclick="location.reload()">🔄 새로고침</button>
                            <button class="btn btn-secondary" onclick="window.print()">🖨️ 인쇄</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 번역가 수정 모달(팝업) 추가 -->
        <div id="editTranslatorModal" style="display:none; position:fixed; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.3); z-index:9999; align-items:center; justify-content:center;">
          <div style="background:white; padding:30px; border-radius:10px; min-width:300px; max-width:90vw; margin:100px auto;">
            <h4>번역가 정보 수정</h4>
            <form method="POST" id="editTranslatorForm">
              <input type="hidden" name="action" value="edit_translator">
              <input type="hidden" name="translator_id" id="edit_translator_id">
              <div style="margin-bottom:10px;">
                <label>역할</label>
                <select name="role" id="edit_role" class="form-control" required>
                  <option value="Translator">번역가</option>
                  <option value="Reviewer">검토자</option>
                  <option value="Manager">관리자</option>
                </select>
              </div>
              <div style="margin-bottom:10px;">
                <label>상태</label>
                <select name="status" id="edit_status" class="form-control" required>
                  <option value="Active">활성</option>
                  <option value="Inactive">비활성</option>
                  <option value="Suspended">정지</option>
                </select>
              </div>
              <div style="text-align:right;">
                <button type="button" class="btn btn-secondary" onclick="closeEditTranslatorModal()">취소</button>
                <button type="submit" class="btn btn-primary">저장</button>
              </div>
            </form>
          </div>
        </div>
        <script>
            function showTab(tabName) {
                // 모든 탭 내용 숨기기
                const tabContents = document.querySelectorAll('.tab-content');
                tabContents.forEach(content => {
                    content.classList.remove('active');
                });
                
                // 모든 탭 버튼 비활성화
                const navTabs = document.querySelectorAll('.nav-tab');
                navTabs.forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // 선택된 탭 내용 보이기
                document.getElementById(tabName).classList.add('active');
                
                // 선택된 탭 버튼 활성화
                event.target.classList.add('active');
            }
            
            function exportToCSV() {
                // CSV 내보내기 기능
                const data = <?php echo isset($_SESSION['qa_analysis_result']) ? json_encode($_SESSION['qa_analysis_result']['qa_data']) : '[]'; ?>;
                
                if (data.length === 0) {
                    alert('내보낼 데이터가 없습니다.');
                    return;
                }
                
                // CSV 헤더
                const headers = ['translator_id', 'source', 'correction', 'error_desc', 'error_group', 'error_subgroup', 'severity'];
                
                // CSV 데이터 생성
                let csvContent = '\uFEFF'; // BOM 추가
                csvContent += headers.join(',') + '\n';
                
                data.forEach(row => {
                    const values = headers.map(header => {
                        const value = row[header] || '';
                        return '"' + value.replace(/"/g, '""') + '"';
                    });
                    csvContent += values.join(',') + '\n';
                });
                
                // 파일 다운로드
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'qa_analysis_' + new Date().toISOString().slice(0, 10) + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            
            function exportTranslatorsToCSV() {
                // 번역가 목록 CSV 내보내기 기능
                const translators = <?php 
                    try {
                        $pdo = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
                        $translators = getTranslators($pdo);
                        echo json_encode($translators);
                    } catch (Exception $e) {
                        echo '[]';
                    }
                ?>;
                
                if (translators.length === 0) {
                    alert('내보낼 번역가 데이터가 없습니다.');
                    return;
                }
                
                // CSV 헤더
                const headers = ['translator_id', 'name', 'email', 'join_date', 'role', 'status'];
                
                // CSV 데이터 생성
                let csvContent = '\uFEFF'; // BOM 추가
                csvContent += headers.join(',') + '\n';
                
                translators.forEach(translator => {
                    const values = headers.map(header => {
                        const value = translator[header] || '';
                        return '"' + value.replace(/"/g, '""') + '"';
                    });
                    csvContent += values.join(',') + '\n';
                });
                
                // 파일 다운로드
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', 'translators_list_' + new Date().toISOString().slice(0, 10) + '.csv');
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            
            // 번역가 선택 기능
            function selectTranslator(translatorId, translatorName) {
                document.getElementById('selectedTranslatorId').value = translatorId;
                document.getElementById('selectedTranslatorName').textContent = translatorName;
                document.getElementById('selectedTranslatorName').style.color = '#28a745';
                document.getElementById('uploadBtn').disabled = false;
            }
            
            // 페이지 로드 시 번역가 선택 이벤트 설정
            document.addEventListener('DOMContentLoaded', function() {
                const radioButtons = document.querySelectorAll('input[name="selected_translator"]');
                const translators = <?php 
                    try {
                        $pdo = createEngine($DATABASE_URL, $DB_USER, $DB_PASS);
                        $translators = getTranslators($pdo);
                        echo json_encode($translators);
                    } catch (Exception $e) {
                        echo '[]';
                    }
                ?>;
                
                radioButtons.forEach(radio => {
                    radio.addEventListener('change', function() {
                        if (this.checked) {
                            const translatorId = this.value;
                            const translator = translators.find(t => t.translator_id === translatorId);
                            if (translator) {
                                selectTranslator(translatorId, translator.name);
                            }
                        }
                    });
                });
            });

            function showEditTranslatorModal(id, role, status) {
              document.getElementById('edit_translator_id').value = id;
              document.getElementById('edit_role').value = role;
              document.getElementById('edit_status').value = status;
              document.getElementById('editTranslatorModal').style.display = 'flex';
            }

            function closeEditTranslatorModal() {
              document.getElementById('editTranslatorModal').style.display = 'none';
            }

            // 툴바 버튼 활성화 및 선택 관리
            const checkboxes = document.querySelectorAll('.translator-checkbox');
            const selectAll = document.getElementById('selectAllTranslators');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const editBtn = document.getElementById('editSelectedBtn');
            const selectedCount = document.getElementById('selectedCount');

            function updateToolbar() {
              const checked = document.querySelectorAll('.translator-checkbox:checked');
              deleteBtn.disabled = checked.length === 0;
              editBtn.disabled = checked.length !== 1;
              selectedCount.textContent = checked.length > 0 ? `${checked.length}명 선택됨` : '';
            }
            checkboxes.forEach(cb => cb.addEventListener('change', updateToolbar));
            if (selectAll) {
              selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = selectAll.checked);
                updateToolbar();
              });
            }

            // 삭제 버튼 클릭 시
            if (deleteBtn) {
              deleteBtn.addEventListener('click', function() {
                const checked = document.querySelectorAll('.translator-checkbox:checked');
                if (checked.length === 0) return;
                if (!confirm('정말 삭제하시겠습니까?')) return;
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_translator_multi">` +
                  Array.from(checked).map(cb => `<input type="hidden" name="translator_ids[]" value="${cb.value}">`).join('');
                document.body.appendChild(form);
                form.submit();
              });
            }
            // 수정 버튼 클릭 시
            if (editBtn) {
              editBtn.addEventListener('click', function() {
                const checked = document.querySelectorAll('.translator-checkbox:checked');
                if (checked.length !== 1) return;
                const cb = checked[0];
                showEditTranslatorModal(cb.value, cb.dataset.role, cb.dataset.status);
              });
            }
        </script>
    </body>
    </html>
    <?php endif; ?>

