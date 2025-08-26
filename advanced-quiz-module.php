<?php
/**
 * Plugin Name: Psych Advanced Quiz Module
 * Plugin URI: https://example.com/psych-quiz
 * Description: Advanced Quiz Module for Psych System with inline JS/CSS, optional AI integration via GapGPT API, various question types including MCQ, Likert, Open, Ranking, Drag and Drop, Matrix, Slider. Supports subscale scoring per option. Use shortcodes like [psych_advanced_quiz] for quizzes. AI is optional via 'ai=true' parameter. Includes form builder capabilities, visual report cards, diverse shortcodes, PDF export for reports, and integration with previous quiz shortcodes as a sub-module. Separate shortcodes for AI input/output.
 * Version: 1.2.0
 * Author: Grok AI
 * Author URI: https://example.com
 * License: GPL2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Psych_Advanced_Quiz_Module {
    private $db_version = '1.2';
    private $table_name = 'wp_psych_quiz_results';
    private $openai_api_key_option = 'psych_gapgpt_api_key'; // Option name for API key
    private $api_base_url = 'https://api.gapgpt.app/v1'; // Change to 'https://api.gapapi.com/v1' if needed

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'register_shortcodes'));
        add_action('wp_ajax_save_quiz_results', array($this, 'save_quiz_results_ajax'));
        add_action('wp_ajax_nopriv_save_quiz_results', array($this, 'save_quiz_results_ajax'));
        add_action('wp_ajax_get_user_rank', array($this, 'get_user_rank_ajax'));
        add_action('wp_ajax_nopriv_get_user_rank', array($this, 'get_user_rank_ajax'));
        add_action('wp_ajax_display_leaderboard', array($this, 'display_leaderboard_ajax'));
        add_action('wp_ajax_nopriv_display_leaderboard', array($this, 'display_leaderboard_ajax'));
        add_action('wp_ajax_generate_pdf_report', array($this, 'generate_pdf_report_ajax')); // New for PDF export

        // Hook for Path Engine integration: Trigger mission completion
        add_action('psych_quiz_completed', array($this, 'integrate_with_path_engine'), 10, 5);

        // Integration with Coach Module for Response Mode
        add_action('psych_quiz_completed', array($this, 'handle_coach_response_submission'), 10, 3);

        // Enqueue inline styles and scripts when shortcode is used
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline_assets'));

        // Admin menu for settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
	public function handle_quiz_submission() {
    check_ajax_referer('psych_quiz_nonce', 'nonce');

    $quiz_id = sanitize_text_field($_POST['quiz_id']);
    $responses = wp_kses_post($_POST['responses']);

    if (empty($quiz_id) || empty($responses)) {
        wp_send_json_error(['message' => 'داده‌های نامعتبر.']);
    }

    $user_id = get_current_user_id();
    $score = $this->calculate_quiz_score($quiz_id, $responses);

    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'psych_quiz_results', [
        'user_id' => $user_id,
        'quiz_id' => $quiz_id,
        'score' => $score,
        'responses' => json_encode($responses),
        'created_at' => current_time('mysql')
    ]);

    // Award points on quiz complete (e.g., 10 points fixed)
    psych_gamification_add_points($user_id, 10, 'تکمیل کوئیز');

    // Trigger the hook
    $station_node_id = sanitize_text_field($_POST['station_node_id'] ?? '');
    do_action('psych_quiz_completed', $user_id, $quiz_id, $score, $responses, $station_node_id);

    wp_send_json_success(['message' => 'کوئیز با موفقیت تکمیل شد!', 'score' => $score]);
}

    public function integrate_with_path_engine($user_id, $quiz_id, $score, $responses, $station_node_id) {
        if (empty($station_node_id) || !class_exists('PsychoCourse_Path_Engine')) {
            return;
        }

        $path_engine = PsychoCourse_Path_Engine::get_instance();

        // This is a simplified integration. We assume the station has some metadata
        // defining the required score. e.g., 'quiz_required_score'.
        // For now, we will just complete the station if a quiz is submitted.

        // A more advanced implementation would fetch station data and check conditions.
        // For example:
        // $station_data = $path_engine->get_station_data($station_node_id);
        // if ($score >= $station_data['required_score']) {
        //     $path_engine->mark_station_as_completed($user_id, $station_node_id, $station_data);
        // }

        // For now, we just complete it.
        $path_engine->public_mark_station_as_completed($user_id, $station_node_id, ['mission_type' => 'quiz']);
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            quiz_id varchar(255) NOT NULL,
            user_id bigint(20) NOT NULL,
            username varchar(255) NOT NULL,
            score int(11) NOT NULL,
            correct_answers int(11) NOT NULL,
            incorrect_answers int(11) NOT NULL,
            time_taken float NOT NULL,
            responses text NOT NULL,  -- JSON for detailed responses, including subscales and values
            ai_analysis text,  -- Optional AI-generated analysis
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('psych_quiz_db_version', $this->db_version);
    }

    public function deactivate() {
        // Optional: Drop table or clean up if needed
    }

    public function add_admin_menu() {
        add_options_page('Psych Quiz Settings', 'Psych Quiz', 'manage_options', 'psych_quiz_settings', array($this, 'settings_page'));
    }

    public function register_settings() {
        register_setting('psych_quiz_options', $this->openai_api_key_option);
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Psych Advanced Quiz Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('psych_quiz_options'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">GapGPT API Key</th>
                        <td><input type="text" name="<?php echo esc_attr($this->openai_api_key_option); ?>" value="<?php echo esc_attr(get_option($this->openai_api_key_option)); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_shortcodes() {
        add_shortcode('psych_advanced_quiz', array($this, 'quiz_shortcode'));
        add_shortcode('psych_quiz_result', array($this, 'quiz_result_shortcode'));
        add_shortcode('psych_quiz_analysis', array($this, 'quiz_analysis_shortcode'));
        add_shortcode('psych_quiz_score', array($this, 'quiz_score_shortcode'));
        add_shortcode('psych_quiz_subscale', array($this, 'quiz_subscale_shortcode'));
        add_shortcode('psych_quiz_answer', array($this, 'quiz_answer_shortcode'));
        add_shortcode('psych_quiz_custom', array($this, 'quiz_custom_shortcode'));
        add_shortcode('psych_leaderboard', array($this, 'leaderboard_shortcode'));
        add_shortcode('psych_quiz_report_card', array($this, 'quiz_report_card_shortcode')); // New for visual report card
        add_shortcode('psych_ai_input', array($this, 'ai_input_shortcode')); // Separate for AI input
        add_shortcode('psych_ai_output', array($this, 'ai_output_shortcode')); // Separate for AI output
        add_shortcode('psych_export_pdf', array($this, 'export_pdf_shortcode')); // For PDF export
        // Attach previous competition quiz shortcode as a sub-module
        add_shortcode('psych_competition_quiz', array($this, 'competition_quiz_shortcode')); // Integrated previous quiz as sub-module

        // Shortcodes for building quizzes
        add_shortcode('quiz_question', array($this, 'capture_question_shortcode'));
        add_shortcode('quiz_option', array($this, 'capture_option_shortcode'));
    }

    public function enqueue_inline_assets() {
        // Inline CSS (expanded for new features: visual reports, PDF button)
        $css = '
            .quiz-container { max-width: 800px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); direction: rtl; text-align: right; }
            .quiz-title { font-size: 24px; font-weight: bold; margin-bottom: 20px; text-align: center; }
            .question { font-size: 18px; margin-bottom: 15px; }
            .options { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
            .options button { padding: 10px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; transition: background 0.3s; }
            .options button:hover { background: #0056b3; }
            .drop-zone { min-height: 100px; border: 2px dashed #ccc; border-radius: 5px; margin: 10px 0; padding: 10px; display: flex; flex-wrap: wrap; gap: 5px; }
            .drag-option, .dropped-item { padding: 8px; background: #e9ecef; border-radius: 5px; cursor: grab; }
            .drag-over { border-color: #007bff; }
            .feedback { margin: 10px 0; padding: 10px; border-radius: 5px; }
            .feedback.correct { background: #d4edda; color: #155724; }
            .feedback.incorrect { background: #f8d7da; color: #721c24; }
            .timer { font-size: 14px; color: #666; margin-bottom: 10px; }
            .result { margin-top: 20px; padding: 15px; background: #e9ecef; border-radius: 5px; }
            .loading-result { display: none; text-align: center; padding: 20px; }
            .loading-spinner { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 10px auto; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            .top-leaderboard { margin-top: 20px; }
            .leaderboard-table { width: 100%; border-collapse: collapse; }
            .leaderboard-table th, .leaderboard-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
            .leaderboard-table th { background: #007bff; color: white; }
            .current-user-row { background: #ffffcc; font-weight: bold; }
            .quiz-l_password { display: none; } /* Hide passwords in forms */
            .quiz-likert-options { display: flex; justify-content: space-between; }
            .quiz-likert-options label { padding: 5px; background: #f0f0f0; border-radius: 5px; cursor: pointer; }
            .quiz-open-textarea { width: 100%; height: 100px; padding: 10px; border-radius: 5px; border: 1px solid #ccc; }
            .quiz-ranking-list { list-style: none; padding: 0; }
            .quiz-ranking-item { padding: 10px; background: #e9ecef; margin-bottom: 5px; cursor: grab; }
            .quiz-matrix-table { width: 100%; border-collapse: collapse; }
            .quiz-matrix-table th, .quiz-matrix-table td { padding: 8px; border: 1px solid #ddd; text-align: center; }
            .quiz-matrix-table input[type="radio"] { margin: 0 auto; display: block; }
            .quiz-slider { width: 100%; margin: 10px 0; }
            .quiz-slider-value { text-align: center; font-size: 16px; margin-top: 5px; }
            .psych-report-card { background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 0 15px rgba(0,0,0,0.2); max-width: 800px; margin: 20px auto; }
            .psych-report-card h2 { text-align: center; color: #007bff; }
            .psych-report-card .section { margin-bottom: 20px; }
            .psych-report-card .score-circle { width: 100px; height: 100px; border-radius: 50%; background: #007bff; color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; margin: 0 auto; }
            .psych-export-pdf-btn { padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; }
            .psych-export-pdf-btn:hover { background: #218838; }
        ';
        wp_register_style('psych-quiz-inline-css', false);
        wp_enqueue_style('psych-quiz-inline-css');
        wp_add_inline_style('psych-quiz-inline-css', $css);

        // Inline JS (preserved and expanded for PDF export button)
        $js = '
            jQuery(document).ready(function($) {
                // General Quiz Logic (preserved from previous)
                $(".quiz-container").each(function() {
                    const container = $(this);
                    const quizId = container.data("quiz-id");
                    const lang = container.data("lang") || "fa";
                    const aiEnabled = container.data("ai") === "true";
                    const texts = {
                        start_quiz: lang === "en" ? "Start Quiz" : "شروع کوئیز",
                        check_answer: lang === "en" ? "Check Answer" : "بررسی پاسخ",
                        question: lang === "en" ? "Question" : "سوال",
                        of: lang === "en" ? "of" : "از",
                        time: lang === "en" ? "Time" : "زمان",
                        seconds: lang === "en" ? "seconds" : "ثانیه",
                        correct: lang === "en" ? "Correct!" : "پاسخ صحیح است!",
                        incorrect: lang === "en" ? "Incorrect. Correct answer: " : "پاسخ اشتباه است. پاسخ صحیح: ",
                        correct_order: lang === "en" ? "Incorrect. Correct order: " : "پاسخ اشتباه. ترتیب صحیح: ",
                        final_result: lang === "en" ? "Final Result" : "نتیجه نهایی",
                        your_rank: lang === "en" ? "Your rank: " : "رتبه شما: ",
                        correct_answers: lang === "en" ? "Correct" : "پاسخ‌های صحیح",
                        incorrect_answers: lang === "en" ? "Incorrect" : "پاسخ‌های اشتباه",
                        total_time: lang === "en" ? "Total time" : "زمان کل",
                        accuracy: lang === "en" ? "Accuracy" : "دقت",
                        congratulations: lang === "en" ? "Congratulations! You are among the best!" : "تبریک! شما جزو برترین‌ها هستید!",
                        drag_instructions: lang === "en" ? "Tap or drag items and arrange them in the correct order below" : "گزینه‌ها را انتخاب کرده و به ترتیب صحیح در کادر زیر مرتب کنید",
                        leaderboard_title: lang === "en" ? "Leaderboard" : "جدول امتیازات",
                        calculating_result: lang === "en" ? "Calculating your result..." : "در حال محاسبه نتیجه...",
                        please_wait: lang === "en" ? "Please wait a moment while we process your answers." : "لطفاً کمی صبر کنید تا پاسخ‌های شما پردازش شوند."
                    };

                    // Parse questions from data attribute (set in PHP)
                    let questions = container.data("questions") || [];

                    let currentQuestion = 0;
                    let score = 0;
                    let incorrectCount = 0;
                    let totalTime = 0;
                    let startTime;
                    let timerInterval;
                    let isProcessingAnswer = false;
                    let responses = {}; // To store detailed responses, including subscales and values

                    const questionElement = container.find(".question");
                    const optionsElement = container.find(".options");
                    const dropZone = container.find(".drop-zone");
                    const dragInstructions = container.find(".drag-drop-instructions");
                    const checkDragButton = container.find(".check-drag-button");
                    const feedbackElement = container.find(".feedback");
                    const loadingResult = container.find(".loading-result");
                    const resultElement = container.find(".result");
                    const timerElement = container.find(".timer");
                    const startButton = container.find(".start-quiz-button");

                    function shuffleArray(array) {
                        const newArray = [...array];
                        for (let i = newArray.length - 1; i > 0; i--) {
                            const j = Math.floor(Math.random() * (i + 1));
                            [newArray[i], newArray[j]] = [newArray[j], newArray[i]];
                        }
                        return newArray;
                    }

                    function startQuiz() {
                        const randomizedQuestions = shuffleArray(questions);
                        showQuestion(randomizedQuestions);
                        startButton.hide();
                    }

                    function showQuestion(questionsList) {
                        isProcessingAnswer = false;
                        const question = questionsList[currentQuestion];
                        questionElement.text(question.question);
                        optionsElement.empty();
                        dropZone.empty();
                        dragInstructions.hide();
                        checkDragButton.hide();
                        feedbackElement.text("").removeClass("correct incorrect");

                        if (question.type === "mcq") {
                            const shuffledOptions = shuffleArray(question.options);
                            shuffledOptions.forEach(opt => {
                                const btn = $("<button>").text(opt.text).data("subscale", opt.subscale).data("value", opt.value).on("click", function() {
                                    if (isProcessingAnswer) return;
                                    isProcessingAnswer = true;
                                    selectAnswer(opt.value, question, opt.subscale, opt.value);
                                });
                                optionsElement.append(btn);
                            });
                        } else if (question.type === "likert") {
                            const scale = question.scale || 5;
                            for (let i = 1; i <= scale; i++) {
                                const label = $("<label>").text(i).data("subscale", question.subscale).data("value", i).on("click", function() {
                                    if (isProcessingAnswer) return;
                                    isProcessingAnswer = true;
                                    selectAnswer(i, question, question.subscale, i);
                                });
                                optionsElement.append(label);
                            }
                        } else if (question.type === "open") {
                            const textarea = $("<textarea>").addClass("quiz-open-textarea").on("change", function() {
                                if (isProcessingAnswer) return;
                                isProcessingAnswer = true;
                                selectAnswer($(this).val(), question, question.subscale, 0); // Default value 0 for open
                            });
                            optionsElement.append(textarea);
                        } else if (question.type === "ranking" || question.type === "dragdrop") {
                            dragInstructions.show();
                            const shuffledOptions = shuffleArray(question.options);
                            shuffledOptions.forEach(opt => {
                                const div = $("<div>").addClass("drag-option").text(opt.text).attr("data-value", opt.value).attr("data-subscale", opt.subscale).attr("data-score", opt.score)
                                    .draggable({ revert: "invalid" })
                                    .on("click", function() { addToDropZone(opt.value, opt.text, opt.subscale, opt.score); $(this).remove(); });
                                optionsElement.append(div);
                            });
                            dropZone.droppable({
                                drop: function(event, ui) {
                                    const value = ui.draggable.data("value");
                                    const text = ui.draggable.text();
                                    const subscale = ui.draggable.data("subscale");
                                    const scoreVal = ui.draggable.data("score");
                                    addToDropZone(value, text, subscale, scoreVal);
                                    ui.draggable.remove();
                                }
                            });
                            checkDragButton.show().on("click", function() {
                                if (isProcessingAnswer) return;
                                isProcessingAnswer = true;
                                const dropped = dropZone.children().map(function() { return {value: $(this).data("value"), subscale: $(this).data("subscale"), score: $(this).data("score") }; }).get();
                                handleDragAnswer(dropped, question);
                            });
                        } else if (question.type === "matrix") {
                            const table = $("<table>").addClass("quiz-matrix-table");
                            const headerRow = $("<tr>").append("<th></th>");
                            question.columns.forEach(col => headerRow.append($("<th>").text(col)));
                            table.append(headerRow);
                            question.rows.forEach((row, rowIndex) => {
                                const rowEl = $("<tr>").append($("<td>").text(row));
                                question.columns.forEach((col, colIndex) => {
                                    const radio = $("<input type=\'radio\'>").attr("name", "matrix_" + rowIndex).data("subscale", question.subscale).data("value", colIndex + 1).on("change", function() {
                                        if (isProcessingAnswer) return;
                                        isProcessingAnswer = true;
                                        selectAnswer(colIndex + 1, question, question.subscale, colIndex + 1);
                                    });
                                    rowEl.append($("<td>").append(radio));
                                });
                                table.append(rowEl);
                            });
                            optionsElement.append(table);
                        } else if (question.type === "slider") {
                            const slider = $("<input type=\'range\'>").addClass("quiz-slider").attr("min", question.min || 0).attr("max", question.max || 100).attr("value", 50).on("input", function() {
                                container.find(".quiz-slider-value").text($(this).val());
                            }).on("change", function() {
                                if (isProcessingAnswer) return;
                                isProcessingAnswer = true;
                                selectAnswer($(this).val(), question, question.subscale, parseInt($(this).val()));
                            });
                            optionsElement.append(slider).append($("<div>").addClass("quiz-slider-value").text(50));
                        }

                        startTime = performance.now();
                        timerInterval = setInterval(() => {
                            const elapsed = ((performance.now() - startTime) / 1000).toFixed(1);
                            timerElement.text(`${texts.time}: ${elapsed} ${texts.seconds}`);
                        }, 100);
                    }

                    function addToDropZone(value, text, subscale, scoreVal) {
                        const item = $("<div>").addClass("dropped-item").text(text).attr("data-value", value).attr("data-subscale", subscale).attr("data-score", scoreVal)
                            .draggable({ revert: "invalid" })
                            .on("click", function() { /* Logic to move back */ });
                        dropZone.append(item);
                    }

                    function selectAnswer(selected, question, subscale, value) {
                        clearInterval(timerInterval);
                        const timeTaken = (performance.now() - startTime) / 1000;
                        totalTime += timeTaken;
                        const isCorrect = selected === question.correct;
                        score += isCorrect ? 1 : 0;
                        incorrectCount += isCorrect ? 0 : 1;
                        responses[question.id] = { value: selected, correct: isCorrect, subscale: subscale, score_value: value };

                        feedbackElement.text(isCorrect ? texts.correct : texts.incorrect + question.correct).addClass(isCorrect ? "correct" : "incorrect");

                        currentQuestion++;
                        if (currentQuestion < questions.length) {
                            setTimeout(() => showQuestion(questions), 2000);
                        } else {
                            showCalculatingScreen();
                        }
                    }

                    function handleDragAnswer(dropped, question) {
                        clearInterval(timerInterval);
                        const timeTaken = (performance.now() - startTime) / 1000;
                        totalTime += timeTaken;
                        const isCorrect = JSON.stringify(dropped.map(d => d.value)) === JSON.stringify(question.correct_order);
                        score += isCorrect ? 1 : 0;
                        incorrectCount += isCorrect ? 0 : 1;
                        responses[question.id] = { order: dropped, correct: isCorrect };

                        // Aggregate subscale scores for drag answers
                        dropped.forEach(item => {
                            if (item.subscale) {
                                responses[question.id].subscale = item.subscale; // Simplified; expand for multiple
                                responses[question.id].score_value = item.score;
                            }
                        });

                        feedbackElement.text(isCorrect ? texts.correct : texts.correct_order + question.correct_order.join(", ")).addClass(isCorrect ? "correct" : "incorrect");

                        currentQuestion++;
                        if (currentQuestion < questions.length) {
                            setTimeout(() => showQuestion(questions), 2000);
                        } else {
                            showCalculatingScreen();
                        }
                    }

                    function showCalculatingScreen() {
                        loadingResult.show();
                        setTimeout(processFinalResult, 1500);
                    }

                    function processFinalResult() {
                        loadingResult.hide();
                        saveScore(score, responses, totalTime, aiEnabled);
                    }

                    function saveScore(finalScore, finalResponses, finalTime, useAI) {
                        $.ajax({
                            url: "' . admin_url('admin-ajax.php') . '",
                            method: "POST",
                            data: {
                                action: "save_quiz_results",
                                quiz_id: quizId,
                                score: finalScore,
                                responses: JSON.stringify(finalResponses),
                                time_taken: finalTime,
                                ai: useAI ? "true" : "false"
                            },
                            success: function(response) {
                                resultElement.html(`<p>${texts.final_result}</p><p>Score: ${finalScore}</p>`);
                                // Trigger psych_quiz_completed action for Path Engine
                                $.post("' . admin_url('admin-ajax.php') . '", { action: "psych_quiz_completed", quiz_id: quizId, score: finalScore });
                            }
                        });
                    }

                    startButton.on("click", startQuiz);
                });

                // PDF Export Button Logic
                $(".psych-export-pdf-btn").on("click", function() {
                    const quizId = $(this).data("quiz-id");
                    $.ajax({
                        url: "' . admin_url('admin-ajax.php') . '",
                        method: "POST",
                        data: {
                            action: "generate_pdf_report",
                            quiz_id: quizId
                        },
                        success: function(response) {
                            if (response.success) {
                                window.location.href = response.data.url;
                            } else {
                                alert("Error generating PDF: " + response.data);
                            }
                        }
                    });
                });
            });
        ';
        wp_register_script('psych-quiz-inline-js', false);
        wp_enqueue_script('psych-quiz-inline-js');
        wp_add_inline_script('psych-quiz-inline-js', $js);
    }

    private $current_quiz_questions = [];
    private $current_question_options = [];

    public function capture_question_shortcode($atts, $content = null) {
        $this->current_question_options = [];
        do_shortcode($content); // This will trigger capture_option_shortcode

        $question_data = shortcode_atts(array(
            'id' => 'q' . (count($this->current_quiz_questions) + 1),
            'type' => 'mcq',
            'text' => '',
            'subscale' => '',
            'correct' => '',
            'correct_order' => '',
            'rows' => '',
            'columns' => '',
            'min' => 0,
            'max' => 100,
        ), $atts);

        $question_data['options'] = $this->current_question_options;
        $this->current_quiz_questions[] = $question_data;

        return ''; // Return empty string as we are just capturing data
    }

    public function capture_option_shortcode($atts, $content = null) {
        $option_data = shortcode_atts(array(
            'correct' => 'false',
            'value' => '',
            'subscale' => '',
            'score' => 1 // Default score for correct answers in ranking/dragdrop
        ), $atts);

        $option_data['text'] = do_shortcode($content);
        $option_data['correct'] = filter_var($option_data['correct'], FILTER_VALIDATE_BOOLEAN);

        $this->current_question_options[] = $option_data;

        return ''; // Return empty string
    }

    public function quiz_shortcode($atts, $content = null) {
        if (!is_user_logged_in()) {
            return '<p>لطفاً برای شرکت در این کوئیز وارد شوید.</p>';
        }

        $this->current_quiz_questions = []; // Reset for each quiz instance

        $atts = shortcode_atts(array(
            'id' => 'default',
            'title' => '',
            'lang' => 'fa',
            'ai' => 'false',
            'station_node_id' => ''
        ), $atts);

        do_shortcode($content); // This populates $this->current_quiz_questions
        $questions = $this->current_quiz_questions;

        ob_start();
        ?>
        <div class="quiz-container" data-quiz-id="<?php echo esc_attr($atts['id']); ?>" data-lang="<?php echo esc_attr($atts['lang']); ?>" data-ai="<?php echo esc_attr($atts['ai']); ?>" data-questions='<?php echo esc_attr(json_encode($questions, JSON_UNESCAPED_UNICODE)); ?>'>
            <div class="quiz-title"><?php echo esc_html($atts['title']); ?></div>
            <div class="question"></div>
            <div class="timer"></div>
            <div class="drag-drop-instructions" style="display:none;"></div>
            <div class="options"></div>
            <div class="drop-zone"></div>
            <button class="check-drag-button" style="display:none;">بررسی پاسخ</button>
            <div class="feedback"></div>
            <div class="loading-result" style="display:none;">
                <div class="loading-text">در حال محاسبه نتیجه...</div>
                <div class="loading-spinner"></div>
                <div class="loading-progress">لطفاً کمی صبر کنید...</div>
            </div>
            <div class="result"></div>
            <button class="start-quiz-button">شروع کوئیز</button>
        </div>
        <?php
        return ob_get_clean();
    }

    // AJAX Handlers (preserved and expanded)
    public function save_quiz_results_ajax() {
        global $wpdb;
        $quiz_id = sanitize_text_field($_POST['quiz_id']);
        $user_id = get_current_user_id();
        $username = wp_get_current_user()->display_name;
        $score = intval($_POST['score']);
        $correct = $score; // Simplified
        $incorrect = count(json_decode($_POST['responses'], true)) - $score;
        $time_taken = floatval($_POST['time_taken']);
        $responses = sanitize_textarea_field($_POST['responses']);
        $use_ai = $_POST['ai'] === 'true';

        // Delete previous entry
        $wpdb->delete($this->table_name, array('quiz_id' => $quiz_id, 'user_id' => $user_id));

        $ai_analysis = $use_ai ? $this->generate_ai_analysis($responses) : '';

        // Insert new
        $wpdb->insert($this->table_name, array(
            'quiz_id' => $quiz_id,
            'user_id' => $user_id,
            'username' => $username,
            'score' => $score,
            'correct_answers' => $correct,
            'incorrect_answers' => $incorrect,
            'time_taken' => $time_taken,
            'responses' => $responses,
            'ai_analysis' => $ai_analysis,
        ));

        wp_send_json_success();
    }

    private function generate_ai_analysis($responses) {
        $api_key = get_option($this->openai_api_key_option);
        if (empty($api_key)) return '';

        $prompt = "Analyze this quiz responses: " . $responses . " Provide subscales, averages, totals, and psychological insights.";
        $response = wp_remote_post($this->api_base_url . '/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-3.5-turbo',
                'messages' => array(array('role' => 'user', 'content' => $prompt)),
            )),
        ));

        if (is_wp_error($response)) return '';

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['choices'][0]['message']['content'] ?? '';
    }

    public function get_user_rank_ajax() {
        global $wpdb;
        $quiz_id = sanitize_text_field($_POST['quiz_id']);
        $username = sanitize_text_field($_POST['username']);

        $query = $wpdb->prepare(
            "SELECT (SELECT COUNT(*) + 1 FROM $this->table_name sub WHERE sub.quiz_id = %s AND (sub.score > main.score OR (sub.score = main.score AND sub.time_taken < main.time_taken))) as user_rank
            FROM $this->table_name main WHERE main.quiz_id = %s AND main.username = %s",
            $quiz_id, $quiz_id, $username
        );

        $rank = $wpdb->get_var($query);
        wp_send_json_success(array('rank' => $rank ? $rank : 0));
    }

    public function display_leaderboard_ajax() {
        $quiz_id = sanitize_text_field($_POST['quiz_id']);
        $limit = intval($_POST['limit']) ?: 10;
        $lang = sanitize_text_field($_POST['lang']) ?: 'fa';

        wp_send_json_success(array('html' => $this->display_leaderboard($quiz_id, get_current_user_id(), $limit, $lang)));
    }

    private function display_leaderboard($quiz_id, $current_user_id, $limit, $lang) {
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE quiz_id = %s ORDER BY score DESC, time_taken ASC LIMIT %d",
            $quiz_id, $limit
        );
        $results = $wpdb->get_results($query);

        if (empty($results)) return '<p>هیچ رکوردی موجود نیست.</p>';

        $output = '<table class="leaderboard-table"><thead><tr><th>رتبه</th><th>نام</th><th>امتیاز</th><th>زمان</th></tr></thead><tbody>';
        foreach ($results as $index => $row) {
            $rank = $index + 1;
            $class = ($row->user_id == $current_user_id) ? 'current-user-row' : '';
            $output .= "<tr class='$class'><td>$rank</td><td>{$row->username}</td><td>{$row->score}</td><td>{$row->time_taken}</td></tr>";
        }
        $output .= '</tbody></table>';
        return $output;
    }

    // New AJAX for PDF Generation
    public function generate_pdf_report_ajax() {
        $quiz_id = sanitize_text_field($_POST['quiz_id']);
        $user_id = get_current_user_id();

        // Generate report content
        $report_content_html = $this->generate_report_content($quiz_id, $user_id);

        // --- PDF Generation ---
        // DEVELOPER NOTE: To enable real PDF generation, a library like Dompdf or TCPDF is required.
        // 1. Install the library (e.g., `composer require dompdf/dompdf`).
        // 2. Include the autoload file: `require_once 'vendor/autoload.php';`
        // 3. Uncomment and adapt the code below.
        /*
        try {
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'dejavu sans'); // Important for Persian characters

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($report_content_html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Instead of saving to a file, stream it to the user
            $dompdf->stream("report-".$quiz_id.".pdf", array("Attachment" => false));
            exit; // Stop WordPress from sending further output
        } catch (Exception $e) {
            wp_send_json_error('Error creating PDF: ' . $e->getMessage());
        }
        */

        // Fallback: Save as a temporary HTML file and provide a link.
        $upload_dir = wp_upload_dir();
        $report_filename = 'psych-report-' . $quiz_id . '-' . $user_id . '-' . wp_rand(1000, 9999) . '.html';
        $report_filepath = $upload_dir['path'] . '/' . $report_filename;
        $report_url = $upload_dir['url'] . '/' . $report_filename;

        if (file_put_contents($report_filepath, $report_content_html)) {
            wp_send_json_success(array('url' => $report_url, 'is_pdf' => false));
        } else {
            wp_send_json_error(array('message' => 'Could not write report file.'));
        }
    }

    private function generate_report_content($quiz_id, $user_id) {
        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $quiz_id, $user_id));
        $user_info = get_userdata($user_id);

        if (!$result || !$user_info) return '<html><body><p>نتیجه‌ای یافت نشد.</p></body></html>';

        $responses = json_decode($result->responses, true);
        $subscale_scores = [];

        foreach ($responses as $question_id => $response) {
            if (isset($response['subscale']) && !empty($response['subscale'])) {
                if (!isset($subscale_scores[$response['subscale']])) {
                    $subscale_scores[$response['subscale']] = 0;
                }
                $subscale_scores[$response['subscale']] += intval($response['score_value']);
            }
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa-IR">
        <head>
            <meta charset="UTF-8">
            <title>کارنامه آزمون <?php echo esc_html($quiz_id); ?></title>
            <style>
                body { font-family: "dejavu sans", "tahoma"; background: #f4f4f4; color: #333; direction: rtl; text-align: right; }
                .report-container { max-width: 800px; margin: 20px auto; padding: 30px; background: #fff; border: 1px solid #ddd; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
                h1 { color: #007bff; text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
                .user-info { margin-bottom: 20px; }
                .section { margin-bottom: 30px; }
                .section h2 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 5px; }
                .score-summary { display: flex; justify-content: space-around; text-align: center; }
                .score-item { padding: 15px; background: #f0f8ff; border-radius: 8px; }
                .score-item .value { font-size: 24px; font-weight: bold; color: #007bff; }
                .subscales ul { list-style: none; padding: 0; }
                .subscales li { padding: 10px; border-bottom: 1px solid #f0f0f0; }
                .subscales li:last-child { border-bottom: none; }
                .ai-analysis { background: #fffbe6; padding: 15px; border-radius: 5px; border-right: 3px solid #ffc107; }
            </style>
        </head>
        <body>
            <div class="report-container">
                <h1>کارنامه آزمون: <?php echo esc_html($quiz_id); ?></h1>
                <div class="user-info">
                    <p><strong>کاربر:</strong> <?php echo esc_html($user_info->display_name); ?></p>
                    <p><strong>تاریخ:</strong> <?php echo date_i18n('Y/m/d'); ?></p>
                </div>

                <div class="section">
                    <h2>خلاصه عملکرد</h2>
                    <div class="score-summary">
                        <div class="score-item">
                            <span class="value"><?php echo (int) $result->score; ?></span>
                            <p>امتیاز کل</p>
                        </div>
                        <div class="score-item">
                            <span class="value"><?php echo (int) $result->correct_answers; ?></span>
                            <p>پاسخ‌های صحیح</p>
                        </div>
                        <div class="score-item">
                             <span class="value"><?php echo round($result->time_taken, 2); ?> ثانیه</span>
                            <p>زمان صرف شده</p>
                        </div>
                    </div>
                </div>

                <?php if (!empty($subscale_scores)): ?>
                <div class="section subscales">
                    <h2>نمرات خرده‌مقیاس‌ها</h2>
                    <ul>
                        <?php foreach ($subscale_scores as $subscale => $score): ?>
                            <li><strong><?php echo esc_html($subscale); ?>:</strong> <?php echo (int) $score; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($result->ai_analysis)): ?>
                <div class="section ai-analysis">
                    <h2>تحلیل هوش مصنوعی</h2>
                    <p><?php echo nl2br(esc_html($result->ai_analysis)); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }


    // Shortcodes for Results and Analysis (preserved and expanded)
    public function quiz_result_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'format' => 'summary'), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));

        if (!$result) return 'No results found.';

        return "Score: {$result->score}, Time: {$result->time_taken}";
    }

    public function quiz_analysis_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => ''), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT ai_analysis FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));

        return $result ? $result->ai_analysis : 'No analysis available.';
    }

    public function quiz_score_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'type' => 'total'), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT responses FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));
        $responses = json_decode($result->responses, true);

        if ($atts['type'] === 'total') {
            return array_sum(array_column($responses, 'score_value'));
        } elseif ($atts['type'] === 'average') {
            $values = array_column($responses, 'score_value');
            return count($values) > 0 ? array_sum($values) / count($values) : 0;
        }
        return 0;
    }

    public function quiz_subscale_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'subscale' => ''), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT responses FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));
        $responses = json_decode($result->responses, true);

        $subscale_scores = array_filter($responses, function($resp) use ($atts) {
            return $resp['subscale'] === $atts['subscale'];
        });
        $total = array_sum(array_column($subscale_scores, 'score_value'));
        return "Subscale {$atts['subscale']}: $total";
    }

    public function quiz_answer_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'question_id' => '', 'show_label' => 'false'), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT responses FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));
        $responses = json_decode($result->responses, true);

        $answer = $responses[$atts['question_id']] ?? null;
        if (!$answer) return 'No answer found.';

        $output = "Answer: {$answer['value']} (Score: {$answer['score_value']})";
        if ($atts['show_label'] === 'true') {
            $output .= " Subscale: {$answer['subscale']}";
        }
        return $output;
    }

    public function quiz_custom_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'calculation' => 'sum', 'questions' => 'all'), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT responses FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));
        $responses = json_decode($result->responses, true);

        $q_list = $atts['questions'] === 'all' ? array_keys($responses) : explode(',', $atts['questions']);
        $values = array_filter(array_map(function($q) use ($responses) { return $responses[$q]['score_value'] ?? 0; }, $q_list));

        if ($atts['calculation'] === 'sum') {
            return array_sum($values);
        } elseif ($atts['calculation'] === 'average') {
            return count($values) > 0 ? array_sum($values) / count($values) : 0;
        }
        return 0;
    }

    public function leaderboard_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => '', 'limit' => 10, 'lang' => 'fa'), $atts);
        return $this->display_leaderboard($atts['quiz_id'], get_current_user_id(), $atts['limit'], $atts['lang']);
    }

    // New Shortcode for Visual Report Card
    public function quiz_report_card_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => ''), $atts);
        global $wpdb;
        $user_id = get_current_user_id();
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE quiz_id = %s AND user_id = %d", $atts['quiz_id'], $user_id));

        if (!$result) return 'No results found.';

        ob_start();
        ?>
        <div class="psych-report-card">
            <h2>کارنامه آزمون <?php echo esc_html($atts['quiz_id']); ?></h2>
            <div class="section">
                <div class="score-circle"><?php echo esc_html($result->score); ?></div>
                <p>امتیاز کلی</p>
            </div>
            <div class="section">
                <p>تحلیل: <?php echo esc_html($result->ai_analysis); ?></p>
            </div>
            <button class="psych-export-pdf-btn" data-quiz-id="<?php echo esc_attr($atts['quiz_id']); ?>">دانلود PDF</button>
        </div>
        <?php
        return ob_get_clean();
    }

    // Separate Shortcodes for AI
    public function ai_input_shortcode($atts, $content = null) {
        // Form for AI input
        ob_start();
        ?>
        <form id="psych-ai-input-form">
            <textarea name="ai_prompt" placeholder="پرامپت خود را وارد کنید"></textarea>
            <button type="submit">ارسال به AI</button>
        </form>
        <?php
        return ob_get_clean();
    }

    public function ai_output_shortcode($atts) {
        $atts = shortcode_atts(array('prompt' => ''), $atts);
        return $this->generate_ai_analysis($atts['prompt']);
    }

    // Shortcode for PDF Export (triggers AJAX)
    public function export_pdf_shortcode($atts) {
        $atts = shortcode_atts(array('quiz_id' => ''), $atts);
        return '<button class="psych-export-pdf-btn" data-quiz-id="' . esc_attr($atts['quiz_id']) . '">دانلود PDF کارنامه</button>';
    }

    // Integrated Previous Competition Quiz as Sub-Module
    public function competition_quiz_shortcode($atts, $content = null) {
        // Implement previous competition quiz logic here (copied and attached as sub-module)
        // For example, similar to quiz_shortcode but with competition features like leaderboard focus
        return $this->quiz_shortcode($atts, $content); // Simplified integration
    }

    public function handle_coach_response_submission($user_id, $quiz_id, $responses) {
        if (function_exists('psych_path_get_viewing_context')) {
            $context = psych_path_get_viewing_context(); // From path-engine.php
            if ($context['is_impersonating']) {
                $student_id = $context['viewed_user_id'];
                // Save responses under student_id instead of coach
                update_user_meta($student_id, 'psych_quiz_responses_' . $quiz_id, $responses);
                do_action('psych_coach_quiz_response_submitted', $context['real_user_id'], $student_id, $quiz_id);
            }
        }
    }
}

new Psych_Advanced_Quiz_Module();
