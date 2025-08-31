<?php
/**
 * Activity Class: Ultimate Quiz Engine
 *
 * Version: 3.0.0
 * This self-contained class manages all logic for a multi-type advanced quiz activity.
 * Supports: Multiple Choice, Dichotomous (True/False), Fill in the Blank, Rating (Likert),
 * Drag & Drop Ranking, Image Choice, Text Area, Matrix, Select (Dropdown), and Matching.
 * Features: Advanced multi-subscale scoring, conversational display mode, results analysis screen,
 * passing score, and a robust results shortcode system.
 *
 * @implements Psych_Activity_Interface
 */
class Psych_Quiz_Activity implements Psych_Activity_Interface {

    private $atts;
    private $content;
    private $quiz_id;

    public function __construct($atts, $content) {
        $this->atts = shortcode_atts([
            'id'                    => 'quiz_' . uniqid(),
            'title'                 => '',
            'display_mode'          => 'standard', // standard, conversational
            'passing_score'         => 0,
            'show_results'          => 'true',
            'sets_flag_on_complete' => '',
            'rewards'               => '',
            'ai_analysis'           => 'false',
            'allowed_actors'        => 'self',
            'required_actors'       => 1,
            'require_coach_approval'=> 'false',
            'target_user_id'        => 0
        ], $atts);
        $this->content = $content;
        $this->quiz_id = esc_attr($this->atts['id']);
    }

    public function render() {
        $questions = $this->parse_quiz_content($this->content);
        if (empty($questions)) {
            return '<div class="psych-error">ساختار آزمون صحیح نیست. لطفاً شورت‌کدهای سوالات را بررسی کنید.</div>';
        }

        $config = $this->atts;
        $config['questions'] = array_values($questions);

        $data_attrs = sprintf(
            'data-activity-id="%s" data-target-user-id="%s" data-flag="%s" data-rewards="%s" data-needs-approval="%s" data-required-actors="%s" data-config=\'%s\'',
            esc_attr($this->quiz_id), esc_attr($this->atts['target_user_id']), esc_attr($this->atts['sets_flag_on_complete']),
            esc_attr($this->atts['rewards']), esc_attr($this->atts['require_coach_approval']), esc_attr($this->atts['required_actors']),
            esc_attr(json_encode($config, JSON_UNESCAPED_UNICODE))
        );

        $container_class = 'psych-quiz-container display-mode-' . esc_attr($this->atts['display_mode']);
        $html = sprintf('<div class="psych-activity-container %s" data-mission-type="quiz" %s>', $container_class, $data_attrs);

        // --- QUIZ AREA ---
        $html .= '<div class="psych-quiz-main-area">';
        if (!empty($this->atts['title'])) {
            $html .= '<h3 class="psych-quiz-title">' . esc_html($this->atts['title']) . '</h3>';
        }
        $html .= '<div class="psych-quiz-progress-wrapper"><div class="psych-quiz-counter">سوال <span class="current-q">1</span> از <span class="total-q">' . count($questions) . '</span></div><div class="psych-quiz-progress-bar"><div class="progress-fill"></div></div></div>';
        $html .= '<div class="psych-quiz-questions-wrapper">';

        foreach ($questions as $index => $q) {
            $html .= '<div class="psych-question" id="' . esc_attr($q['id']) . '" data-question-type="'.esc_attr($q['type']).'" style="display: ' . ($index === 0 ? 'block' : 'none') . ';">';
            $html .= '<p class="psych-question-text">' . wp_kses_post($q['text']) . '</p>';
            $html .= $this->render_question_body($q);
            $html .= '</div>';
        }

        $html .= '</div>'; // End questions-wrapper
        $html .= '<div class="psych-quiz-navigation"><button class="psych-quiz-nav-btn prev" style="display: none;">قبلی</button><button class="psych-quiz-nav-btn next">بعدی</button></div>';
        $html .= '</div>'; // End quiz-main-area

        // --- RESULTS AREA (Initially Hidden) ---
        $html .= '<div class="psych-quiz-results-area" style="display:none;">';
        $html .= '<h3>نتایج آزمون</h3><div class="psych-results-summary"><div class="spinner"></div></div>';
        $html .= $this->render_footer();
        $html .= '</div>';

        $html .= '</div>'; // End container
        return $html;
    }

    public function get_css() {
        return "
        .psych-quiz-container { text-align: center; }
        .psych-quiz-title { color: #343a40; margin-bottom: 25px; }
        .psych-quiz-progress-wrapper { margin-bottom: 30px; }
        .psych-quiz-counter { font-size: 0.9em; color: #6c757d; margin-bottom: 8px; }
        .psych-quiz-progress-bar { background: #e9ecef; border-radius: 5px; height: 8px; overflow: hidden; }
        .psych-quiz-progress-bar .progress-fill { background: linear-gradient(90deg, #3498db, #2980b9); height: 100%; width: 0%; transition: width 0.3s ease; }
        .psych-question { animation: fadeIn 0.4s ease; }
        .psych-question-text { font-size: 1.2em; font-weight: 500; color: #212529; min-height: 50px; line-height: 1.6; }
        .psych-options-list, .psych-dichotomous-options, .psych-image-choice-options { margin-top: 20px; display: flex; flex-wrap: wrap; justify-content: center; gap: 15px; }
        .psych-option label { display: block; background: #fff; border: 2px solid #ced4da; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-radius: 8px; padding: 15px 20px; cursor: pointer; transition: all 0.2s ease; text-align: center; }
        .psych-option input[type='radio'], .psych-option input[type='checkbox'] { display: none; }
        .psych-option input:checked + label { background: #007bff; color: white; border-color: #0056b3; font-weight: bold; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,123,255,0.2); }
        .psych-option label:hover { border-color: #007bff; }
        .psych-image-choice-option label { padding: 10px; }
        .psych-image-choice-option img { max-width: 150px; border-radius: 6px; }
        .psych-quiz-navigation { margin-top: 30px; display: flex; justify-content: center; gap: 15px; }
        .psych-quiz-nav-btn { background: #6c757d; color: white; border: none; padding: 10px 30px; border-radius: 6px; cursor: pointer; }
        .psych-quiz-nav-btn.next { background: #007bff; }
        .psych-fill-blank-input, .psych-text-area, .psych-select { padding: 12px; border: 2px solid #ced4da; border-radius: 8px; width: 100%; max-width: 400px; text-align: center; font-size: 1.1em; margin-top: 20px; }
        .psych-text-area { text-align: right; min-height: 120px; }
        .psych-drag-drop-area, .psych-matching-area { display: flex; justify-content: center; gap: 30px; margin-top: 20px; text-align: right; }
        .psych-drag-drop-list, .psych-matching-list { list-style:none; padding:15px; border: 2px dashed #ced4da; border-radius: 8px; min-height: 200px; width: 250px; }
        .psych-drag-item, .psych-matching-item { background: white; border: 1px solid #ddd; padding: 10px; margin-bottom: 5px; border-radius: 6px; cursor: grab; }
        .psych-matching-target { background: #f8f9fa; }
        .psych-quiz-results-area { padding-top: 20px; text-align: right; }
        .psych-results-summary table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .psych-results-summary th, td { padding: 12px; border-bottom: 1px solid #eee; }
        .psych-results-summary th { background: #f8f9fa; font-weight: bold; }
        .psych-results-summary .score-value { font-weight: bold; color: #007bff; font-size: 1.1em; }
        .psych-results-summary .pass-status.passed { color: #28a745; }
        .psych-results-summary .pass-status.failed { color: #dc3545; }
        /* Conversational Mode */
        .display-mode-conversational .psych-question { background: #f1f3f4; padding: 20px; border-radius: 15px; margin-bottom: 15px; max-width: 90%; text-align: right; }
        .display-mode-conversational .psych-options-list { flex-direction: row; flex-wrap: wrap; justify-content: flex-end; }
        .display-mode-conversational .psych-option { width: auto; }
        .display-mode-conversational .psych-quiz-questions-wrapper { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .display-mode-conversational .psych-quiz-navigation, .display-mode-conversational .psych-quiz-progress-wrapper { display: none; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        ";
    }

    public function get_javascript() {
        return "
        // Load SortableJS library for drag & drop functionality
        if (typeof Sortable === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js';
            document.head.appendChild(script);
        }

        $('.psych-quiz-container').each(function() {
            const container = $(this);
            const config = container.data('config');
            const isConversational = config.display_mode === 'conversational';
            const questions = config.questions || [];
            const totalQuestions = questions.length;
            let currentQuestionIndex = 0;
            let userAnswers = {};

            // Cache jQuery elements
            const mainArea = container.find('.psych-quiz-main-area');
            const resultsArea = container.find('.psych-quiz-results-area');
            const questionsWrapper = container.find('.psych-quiz-questions-wrapper');
            const progressFill = container.find('.progress-fill');
            const currentQSpan = container.find('.current-q');
            const prevBtn = container.find('.psych-quiz-nav-btn.prev');
            const nextBtn = container.find('.psych-quiz-nav-btn.next');
            const submitBtn = resultsArea.find('.psych-activity-submit-btn');

            function showQuestion(index) {
                const questionElement = questionsWrapper.find('#' + questions[index].id);
                if (isConversational) {
                    questionElement.fadeIn(400);
                } else {
                    questionsWrapper.find('.psych-question').hide();
                    questionElement.fadeIn(300);
                }
                updateProgress(index);
                initSortableForQuestion(questionElement);
            }

            function updateProgress(index) {
                const progress = ((index + 1) / totalQuestions) * 100;
                progressFill.css('width', progress + '%');
                currentQSpan.text(index + 1);
                prevBtn.toggle(!isConversational && index > 0);
                nextBtn.text(index === totalQuestions - 1 ? 'مشاهده نتایج' : 'بعدی');
                nextBtn.toggle(!isConversational);
            }

            function initSortableForQuestion(questionElement) {
                if (questionElement.data('question-type') === 'drag_drop_ranking' || questionElement.data('question-type') === 'matching') {
                     if (typeof Sortable !== 'undefined') {
                        questionElement.find('.psych-drag-drop-list, .psych-matching-list').each(function() {
                           new Sortable(this, { group: 'shared', animation: 150 });
                        });
                     }
                }
            }

            function handleNext() {
                if (!validateAndSaveAnswer(currentQuestionIndex)) return;

                if (currentQuestionIndex < totalQuestions - 1) {
                    currentQuestionIndex++;
                    showQuestion(currentQuestionIndex);
                } else {
                    finishQuiz();
                }
            }

            // Auto-advance logic
            container.find('input[type=\"radio\"], .psych-select').on('change', function() {
                if (isConversational || $(this).closest('.psych-question').data('question-type') === 'rating') {
                     setTimeout(() => handleNext(), 300);
                }
            });

            nextBtn.on('click', handleNext);
            prevBtn.on('click', function() {
                if (currentQuestionIndex > 0) {
                    currentQuestionIndex--;
                    showQuestion(currentQuestionIndex);
                }
            });

            submitBtn.on('click', function() {
                const results = calculateResults();
                window.psychActivities.submitActivity(container, { raw_answers: userAnswers, scores: results.subscaleScores, total_score: results.totalScore });
            });

            function validateAndSaveAnswer(index) {
                const q = questions[index];
                let answerData = { raw: null, scores: '' };
                const qElem = $('#' + q.id);

                switch (q.type) {
                    case 'multiple_choice': case 'dichotomous': case 'rating':
                        const selectedOption = qElem.find('input[type=\"radio\"]:checked');
                        if (selectedOption.length === 0) { if(!isConversational) alert('لطفاً یک گزینه را انتخاب کنید.'); return false; }
                        answerData = { raw: selectedOption.val(), scores: selectedOption.data('scores') || '' };
                        break;
                    case 'image_choice':
                         const checkedImages = qElem.find('input:checked');
                         if (checkedImages.length === 0) { if(!isConversational) alert('لطفاً حداقل یک تصویر را انتخاب کنید.'); return false; }
                         const selectedVals = $.map(checkedImages, item => $(item).val());
                         const selectedScores = $.map(checkedImages, item => $(item).data('scores')).join(',');
                         answerData = { raw: selectedVals, scores: selectedScores };
                        break;
                    case 'fill_blank': case 'text_area':
                        const inputVal = qElem.find('input, textarea').val().trim();
                        if (inputVal === '') { if(!isConversational) alert('لطفاً پاسخ را وارد کنید.'); return false; }
                        answerData = { raw: inputVal, scores: q.options.find(opt => opt.text.toLowerCase() === inputVal.toLowerCase())?.scores || '' };
                        break;
                    case 'select':
                         const selectVal = qElem.find('select').val();
                         if (!selectVal) { if(!isConversational) alert('لطفاً یک گزینه را انتخاب کنید.'); return false; }
                         answerData = { raw: selectVal, scores: qElem.find('option:selected').data('scores') || '' };
                        break;
                    case 'drag_drop_ranking':
                        const items = $.map(qElem.find('.psych-drag-item'), item => $(item).data('item-id'));
                        answerData = { raw: items, scores: '' }; // Scoring is complex, done server-side or via interpretation
                        break;
                    case 'matching':
                        const matches = {};
                        qElem.find('.psych-matching-target').each(function() {
                            const targetId = $(this).data('target-id');
                            const itemId = $(this).find('.psych-matching-item').data('item-id') || null;
                            matches[targetId] = itemId;
                        });
                        answerData = { raw: matches, scores: '' };
                        break;
                }
                userAnswers[q.id] = answerData;
                return true;
            }

            function finishQuiz() {
                if (!validateAndSaveAnswer(totalQuestions - 1)) return;
                mainArea.hide();
                resultsArea.fadeIn();
                if (config.show_results === 'true') {
                    displayResults();
                } else {
                    resultsArea.find('h3').text('آزمون شما با موفقیت ثبت شد.');
                    resultsArea.find('.psych-results-summary').remove();
                }
            }

            function calculateResults() {
                let subscaleScores = {}; let totalScore = 0;
                Object.values(userAnswers).forEach(answer => {
                    if (typeof answer.scores === 'string' && answer.scores) {
                        answer.scores.split(',').forEach(scorePart => {
                            const [subscale, value] = scorePart.split(':');
                            if (subscale && value) {
                                const numericValue = parseInt(value.trim(), 10);
                                if (!isNaN(numericValue)) {
                                    subscaleScores[subscale.trim()] = (subscaleScores[subscale.trim()] || 0) + numericValue;
                                    totalScore += numericValue;
                                }
                            }
                        });
                    }
                });
                return { subscaleScores, totalScore };
            }

            function displayResults() {
                const results = calculateResults();
                let html = '<table><thead><tr><th>مقیاس</th><th>نمره</th></tr></thead><tbody>';
                for (const subscale in results.subscaleScores) {
                    html += `<tr><td>${subscale}</td><td class='score-value'>${results.subscaleScores[subscale]}</td></tr>`;
                }
                html += '</tbody></table>';
                if (config.passing_score > 0) {
                    const passed = results.totalScore >= config.passing_score;
                    html += `<p class='pass-status ${passed ? 'passed' : 'failed'}'><strong>وضعیت کلی: </strong> ${passed ? 'قبول' : 'مردود'} (نمره کل: ${results.totalScore})</p>`;
                }
                resultsArea.find('.psych-results-summary').html(html);
            }

            // Initial setup
            showQuestion(0);
        });
        ";
    }

    private function render_question_body($q) {
        $html = '';
        switch ($q['type']) {
            case 'fill_blank':
                $html .= '<input type="text" class="psych-fill-blank-input" placeholder="پاسخ خود را اینجا بنویسید...">';
                break;
            case 'text_area':
                $html .= '<textarea class="psych-text-area" placeholder="پاسخ خود را اینجا بنویسید..."></textarea>';
                break;
            case 'select':
                $html .= '<select class="psych-select"><option value="">-- انتخاب کنید --</option>';
                foreach ($q['options'] as $opt) {
                    $html .= '<option value="' . esc_attr($opt['value']) . '" data-scores="' . esc_attr($opt['scores']) . '">' . esc_html($opt['text']) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'drag_drop_ranking':
                $html .= '<div class="psych-drag-drop-area"><div class="psych-drag-drop-list">';
                foreach ($q['options'] as $opt) {
                    $html .= '<div class="psych-drag-item" data-item-id="'.esc_attr($opt['value']).'">'.esc_html($opt['text']).'</div>';
                }
                $html .= '</div></div>';
                break;
            case 'image_choice':
                $html .= '<div class="psych-image-choice-options">';
                foreach ($q['options'] as $opt_index => $opt) {
                    $html .= '<div class="psych-option psych-image-choice-option">';
                    $html .= '  <input type="checkbox" id="opt-'.esc_attr($q['id']).'-'.$opt_index.'" name="' . esc_attr($q['id']) . '[]" value="' . esc_attr($opt['value']) . '" data-scores="' . esc_attr($opt['scores']) . '">';
                    $html .= '  <label for="opt-'.esc_attr($q['id']).'-'.$opt_index.'"><img src="'.esc_url($opt['image_src']).'" alt="'.esc_attr($opt['text']).'"><br><span>' . esc_html($opt['text']) . '</span></label>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                break;
            case 'matrix':
                $html .= '<table class="psych-matrix-table">';
                $html .= '<thead><tr><th></th>';
                foreach($q['columns'] as $col) { $html .= '<th>'.esc_html($col).'</th>'; }
                $html .= '</tr></thead><tbody>';
                foreach($q['rows'] as $row_index => $row) {
                    $html .= '<tr><td>'.esc_html($row).'</td>';
                    foreach($q['columns'] as $col_index => $col) {
                        $scores = $q['scores'][$row_index][$col_index] ?? '';
                        $html .= '<td><input type="radio" name="'.esc_attr($q['id']).'_'.$row_index.'" value="'.esc_attr($col).'" data-scores="'.esc_attr($scores).'"></td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                break;
             case 'matching':
                $html .= '<div class="psych-matching-area">';
                $html .= '<div class="psych-matching-list psych-matching-source">';
                shuffle($q['items']); // Shuffle items for matching
                foreach ($q['items'] as $item) { $html .= '<div class="psych-matching-item" data-item-id="'.esc_attr($item['id']).'">'.esc_html($item['text']).'</div>'; }
                $html .= '</div>';
                $html .= '<div class="psych-matching-targets">';
                foreach ($q['targets'] as $target) { $html .= '<div><span>'.esc_html($target['text']).'</span><div class="psych-matching-list psych-matching-target" data-target-id="'.esc_attr($target['id']).'"></div></div>'; }
                $html .= '</div></div>';
                break;
            default: // multiple_choice, dichotomous, rating
                $html .= '<div class="psych-options-list">';
                foreach ($q['options'] as $opt_index => $opt) {
                    $html .= '<div class="psych-option">';
                    $html .= '  <input type="radio" id="opt-'.esc_attr($q['id']).'-'.$opt_index.'" name="' . esc_attr($q['id']) . '" value="' . esc_attr($opt['value']) . '" data-scores="' . esc_attr($opt['scores']) . '">';
                    $html .= '  <label for="opt-'.esc_attr($q['id']).'-'.$opt_index.'">' . esc_html($opt['text']) . '</label>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                break;
        }
        return $html;
    }

    private function parse_quiz_content($content) {
        $questions = [];
        preg_match_all('/\[quiz_question\s+id="([^"]+)"\s+text="([^"]+)"(?:\s+type="([^"]+)")?\](.*?)\[\/quiz_question\]/s', $content, $q_matches, PREG_SET_ORDER);

        foreach ($q_matches as $q_match) {
            $question_id = $q_match[1];
            $question_type = !empty($q_match[3]) ? $q_match[3] : 'multiple_choice';
            $inner_content = $q_match[4];

            $question_data = [ 'id' => $question_id, 'text' => $q_match[2], 'type' => $question_type, 'options' => [] ];

            switch ($question_type) {
                case 'fill_blank':
                    preg_match_all('/\[quiz_answer\s+correct="([^"]+)"\s+scores="([^"]+)"\]/s', $inner_content, $a_matches, PREG_SET_ORDER);
                    foreach ($a_matches as $a_match) { $question_data['options'][] = ['text' => $a_match[1], 'scores' => $a_match[2]]; }
                    break;
                case 'matrix':
                     preg_match_all('/\[matrix_row\](.*?)\[\/matrix_row\]/s', $inner_content, $rows);
                     preg_match_all('/\[matrix_col\](.*?)\[\/matrix_col\]/s', $inner_content, $cols);
                     preg_match_all('/\[matrix_scores\](.*?)\[\/matrix_scores\]/s', $inner_content, $scores);
                     $question_data['rows'] = $rows[1];
                     $question_data['columns'] = $cols[1];
                     $question_data['scores'] = array_map(fn($s) => explode('|', $s), $scores[1]);
                    break;
                case 'matching':
                    preg_match_all('/\[match_item\s+id="([^"]+)"\](.*?)\[\/match_item\]/s', $inner_content, $items);
                    preg_match_all('/\[match_target\s+id="([^"]+)"\](.*?)\[\/match_target\]/s', $inner_content, $targets);
                    for($i=0; $i<count($items[1]); $i++) { $question_data['items'][] = ['id' => $items[1][$i], 'text' => $items[2][$i]]; }
                    for($i=0; $i<count($targets[1]); $i++) { $question_data['targets'][] = ['id' => $targets[1][$i], 'text' => $targets[2][$i]]; }
                    break;
                default: // Handles multiple_choice, dichotomous, rating, image_choice, drag_drop_ranking, select
                    preg_match_all('/\[quiz_option\s+text="([^"]+)"(?:\s+value="([^"]+)")?(?:\s+scores="([^"]+)")?(?:\s+image_src="([^"]+)")?\]/s', $inner_content, $o_matches, PREG_SET_ORDER);
                    foreach ($o_matches as $o_match) {
                        $question_data['options'][] = [
                            'text' => $o_match[1],
                            'value' => !empty($o_match[2]) ? $o_match[2] : $o_match[1],
                            'scores' => $o_match[3] ?? '',
                            'image_src' => $o_match[4] ?? ''
                        ];
                    }
                    break;
            }
            $questions[$question_id] = $question_data;
        }
        return $questions;
    }

    private function render_footer() {
        return '<div class="psych-activity-footer">
                  <button class="psych-activity-submit-btn">تکمیل و ثبت نهایی</button>
                  <div class="psych-activity-feedback" style="display:none;"></div>
                </div>';
    }
}
