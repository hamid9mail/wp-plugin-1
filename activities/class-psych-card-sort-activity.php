<?php
/**
 * Activity Class: Advanced Card Sort
 *
 * This self-contained class manages all logic, rendering, scripting, and styling
 * for the advanced card sort activity, including pyramid mode, subscale scoring,
 * and double-sided card analysis.
 *
 * @implements Psych_Activity_Interface
 */
class Psych_Card_Sort_Activity implements Psych_Activity_Interface {

    private $atts;
    private $content;

    public function __construct($atts, $content) {
        $this->atts = shortcode_atts([
            'id'                      => 'card_sort_' . uniqid(),
            'mode'                    => 'simple', // 'simple' or 'pyramid'
            'scoring'                 => 'none',   // 'none' or 'subscale'
            'reveal_back'             => 'false',
            'sets_flag_on_complete'   => '',
            'rewards'                 => '',
            'allowed_actors'          => 'self',
            'required_actors'         => 1,
            'require_coach_approval'  => 'false',
            'target_user_id'          => 0 // This will be injected by the core engine
        ], $atts);
        $this->content = $content;
    }

    /**
     * Renders the initial HTML structure for the activity.
     * The entire configuration is passed to the JavaScript handler via data attributes.
     */
    public function render() {
        $stages = $this->parse_content_to_stages();
        if (empty($stages) || empty($stages[0]['cards'])) {
            return '<div class="psych-error">ساختار فعالیت مرتب‌سازی کارت‌ها صحیح نیست. لطفاً از شورت‌کدهای [stage], [category], [cards] و [card] به درستی استفاده کنید.</div>';
        }

        $config = $this->atts;
        $config['stages'] = $stages;

        $data_attrs = sprintf(
            'data-activity-id="%s" data-target-user-id="%s" data-flag="%s" data-rewards="%s" data-needs-approval="%s" data-required-actors="%s" data-config=\'%s\'',
            esc_attr($this->atts['id']),
            esc_attr($this->atts['target_user_id']),
            esc_attr($this->atts['sets_flag_on_complete']),
            esc_attr($this->atts['rewards']),
            esc_attr($this->atts['require_coach_approval']),
            esc_attr($this->atts['required_actors']),
            esc_attr(json_encode($config, JSON_UNESCAPED_UNICODE))
        );

        $first_stage = $stages[0];
        shuffle($first_stage['cards']);

        $html = sprintf('<div class="psych-activity-container psych-card-sort-container" data-mission-type="card_sort" %s>', $data_attrs);
        $html .= '<div class="cs-stage-container">';
        $html .= '<div class="cs-stage active" data-stage-number="1">';

        if (!empty($first_stage['title'])) {
            $html .= '<h3 class="cs-stage-title">' . esc_html($first_stage['title']) . '</h3>';
        }

        $html .= '<div class="cs-main-area">';
        $html .= '<div class="cs-cards-pool"><h4>کارت‌ها</h4>';
        foreach ($first_stage['cards'] as $card) {
            $html .= '<div class="cs-card" draggable="true" id="'.esc_attr($card['id']).'"><div class="cs-card-inner"><div class="cs-card-front">'.esc_html($card['front']).'</div><div class="cs-card-back"><strong>'.esc_html($card['back_type']).'</strong><p>'.esc_html($card['back']).'</p></div></div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="cs-categories-area">';
        foreach ($first_stage['categories'] as $cat) {
            $html .= '<div class="cs-category-dropzone" data-category="'.esc_attr($cat).'"><h5>'.esc_html($cat).'</h5></div>';
        }
        $html .= '</div>';

        $html .= '</div>'; // End .cs-main-area
        $html .= '</div>'; // End .cs-stage
        $html .= '</div>'; // End .cs-stage-container

        $button_text = count($stages) > 1 ? 'مرحله بعد' : 'پایان و ثبت نهایی';
        $html .= '<div class="psych-activity-footer">
                      <button class="psych-activity-submit-btn cs-next-stage-btn">' . esc_html($button_text) . '</button>
                      <div class="psych-activity-feedback" style="display:none;"></div>
                  </div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Provides the dedicated CSS for this activity.
     */
    public function get_css() {
        return "
        .psych-card-sort-container .cs-stage { display: none; }
        .psych-card-sort-container .cs-stage.active { display: block; animation: cs-fadeIn .5s; }
        .psych-card-sort-container .cs-stage-title { text-align: center; margin-bottom: 20px; color: #343a40; }
        .psych-card-sort-container .cs-main-area { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
        .psych-card-sort-container .cs-cards-pool, .cs-categories-area { flex: 1; min-width: 250px; }
        .psych-card-sort-container .cs-cards-pool { border: 2px dashed #ced4da; padding: 15px; border-radius: 8px; background: #f8f9fa; }
        .psych-card-sort-container .cs-card { perspective: 1000px; background: transparent; padding: 0; min-height: 60px; }
        .psych-card-sort-container .cs-card-inner { position: relative; width: 100%; height: 100%; transition: transform 0.6s; transform-style: preserve-3d; }
        .psych-card-sort-container .cs-card.is-flipped .cs-card-inner { transform: none; }
        .psych-card-sort-container .cs-card-front, .cs-card-back { width: 100%; -webkit-backface-visibility: hidden; backface-visibility: hidden; display: flex; align-items: center; justify-content: center; background: #fff; border: 1px solid #ced4da; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 10px 15px; box-sizing: border-box; min-height: 60px; }
        .psych-card-sort-container .cs-card-back { display: none; flex-direction: column; text-align: center; }
        .psych-card-sort-container .cs-card-back strong { font-size: 0.9em; color: #007bff; }
        .psych-card-sort-container .cs-card-back p { font-size: 0.8em; margin: 5px 0 0; color: #6c757d; }
        .psych-card-sort-container .cs-card.is-flipped .cs-card-back { display: flex; }
        .psych-card-sort-container .cs-card.dragging { opacity: 0.5; }
        .psych-card-sort-container .cs-category-dropzone { border: 2px dashed #ced4da; padding: 15px; border-radius: 8px; min-height: 150px; transition: all 0.2s; margin-bottom: 15px; }
        .psych-card-sort-container .cs-category-dropzone h5 { margin-top: 0; text-align: center; color: #6c757d; pointer-events: none; }
        .psych-card-sort-container .cs-category-dropzone.drag-over { background-color: #e2e6ea; border-style: solid; }
        .cs-results-analysis { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 5px solid #007bff; }
        @keyframes cs-fadeIn { from { opacity: 0; } to { opacity: 1; } }
        ";
    }

    /**
     * Provides the dedicated JavaScript for this activity.
     */
    public function get_javascript() {
        // We use esc_js to safely output the activity ID into the script block.
        return "
        $('.psych-card-sort-container[data-activity-id=\"" . esc_js($this->atts['id']) . "\"]').each(function() {
            const container = $(this);
            const config = container.data('config');
            let currentStageIndex = 0;
            let userResponses = {};

            function setupDragAndDrop(stageElement) {
                let draggedCard = null;
                stageElement.find('.cs-card').on('dragstart', function(e) {
                    draggedCard = this;
                    $(this).addClass('dragging');
                    e.originalEvent.dataTransfer.effectAllowed = 'move';
                    e.originalEvent.dataTransfer.setData('text/plain', this.id);
                });
                stageElement.find('.cs-card').on('dragend', function() { $(this).removeClass('dragging'); });
                stageElement.find('.cs-category-dropzone')
                    .on('dragover', function(e) { e.preventDefault(); $(this).addClass('drag-over'); })
                    .on('dragleave', function() { $(this).removeClass('drag-over'); })
                    .on('drop', function(e) {
                        e.preventDefault();
                        $(this).removeClass('drag-over');
                        if (draggedCard) this.appendChild(draggedCard);
                    });
            }

            function renderStage(stageIndex) {
                const stageData = config.stages[stageIndex];
                const stageContainer = container.find('.cs-stage-container');
                let cardsForThisStage = [];
                if (stageData.source_category) {
                    const prevStageNum = stageData.number - 1;
                    const cardIdsFromPrevStage = userResponses[prevStageNum]
                        .filter(item => item.category === stageData.source_category)
                        .map(item => item.cardId);

                    const allCards = config.stages.flatMap(s => s.cards);
                    cardsForThisStage = allCards.filter(c => cardIdsFromPrevStage.includes(c.id));
                } else {
                    cardsForThisStage = stageData.cards;
                }
                let newStageHtml = `<div class='cs-stage' data-stage-number='${stageData.number}'><h3 class='cs-stage-title'>${stageData.title}</h3><div class='cs-main-area'><div class='cs-cards-pool'><h4>کارت‌ها</h4>`;
                cardsForThisStage.forEach(card => { newStageHtml += `<div class='cs-card' draggable='true' id='${card.id}'><div class='cs-card-inner'><div class='cs-card-front'>${card.front}</div><div class='cs-card-back'><strong>${card.back_type}</strong><p>${card.back}</p></div></div></div>`; });
                newStageHtml += `</div><div class='cs-categories-area'>`;
                stageData.categories.forEach(cat => { newStageHtml += `<div class='cs-category-dropzone' data-category='${cat}'><h5>${cat}</h5></div>`; });
                newStageHtml += `</div></div></div>`;
                stageContainer.find('.cs-stage.active').removeClass('active');
                stageContainer.append(newStageHtml).find('.cs-stage:last-child').addClass('active');
                setupDragAndDrop(container.find('.cs-stage.active'));
            }

            container.find('.cs-next-stage-btn').on('click', function() {
                const btn = $(this);
                const activeStage = container.find('.cs-stage.active');
                if (activeStage.find('.cs-cards-pool .cs-card').length > 0) {
                    window.psychActivities.showFeedback(container, 'لطفاً تمام کارت‌ها را در دسته‌ها قرار دهید.', 'error');
                    return;
                }
                const stageNum = activeStage.data('stage-number');
                userResponses[stageNum] = [];
                activeStage.find('.cs-category-dropzone').each(function() {
                    const category = $(this).data('category');
                    $(this).find('.cs-card').each(function() { userResponses[stageNum].push({ cardId: this.id, category: category }); });
                });
                currentStageIndex++;
                if (currentStageIndex < config.stages.length) {
                    renderStage(currentStageIndex);
                    if (currentStageIndex === config.stages.length - 1) btn.text('پایان و ثبت نهایی');
                } else {
                    let finalData = { placements: userResponses };
                    if (config.scoring === 'subscale' || config.reveal_back) {
                        const allCards = config.stages.flatMap(s => s.cards);
                        finalData.analysis = {};
                        config.stages[0].categories.forEach(catName => { finalData.analysis[catName] = {}; });

                        userResponses[1].forEach(placement => {
                            const card = allCards.find(c => c.id === placement.cardId);
                            if (card && card.back_type) {
                                if (!finalData.analysis[placement.category][card.back_type]) finalData.analysis[placement.category][card.back_type] = 0;
                                finalData.analysis[placement.category][card.back_type]++;
                            }
                        });

                        let resultsHtml = '<div class=\"cs-results-analysis\"><h4>تحلیل نتایج</h4>';
                        for (const category in finalData.analysis) {
                            resultsHtml += `<h5>در دسته «${category}»:</h5><ul>`;
                            let hasEntries = false;
                            for (const type in finalData.analysis[category]) {
                                hasEntries = true;
                                resultsHtml += `<li><strong>${type}:</strong> ${finalData.analysis[category][type]} مورد</li>`;
                            }
                            if (!hasEntries) resultsHtml += '<li>هیچ موردی قرار نگرفت.</li>';
                            resultsHtml += '</ul>';
                        }
                        resultsHtml += '</div>';
                        container.find('.cs-stage-container').after(resultsHtml);
                    }
                    if (config.reveal_back === 'true') container.find('.cs-card').addClass('is-flipped');
                    window.psychActivities.submitActivity(container, finalData);
                }
            });
            setupDragAndDrop(container.find('.cs-stage.active'));
        });
        ";
    }

    // --- Private Helper Methods ---

    private function parse_content_to_stages() {
        $stages = [];
        $content = $this->content;
        if ($this->atts['mode'] === 'simple') {
            preg_match_all('/\[category name="([^"]+)"\]/s', $content, $cat_matches);
            preg_match('/\[cards\](.*?)\[\/cards\]/s', $content, $cards_match);
            $stage_content = implode('', $cat_matches[0]);
            $cards_content = $cards_match[0] ?? '';
            $content = sprintf('[stage number="1" title=""]%s %s[/stage]', $stage_content, $cards_content);
        }

        preg_match_all('/\[stage number="(\d+)" title="([^"]*)"(?: source_category="([^"]*)")?\](.*?)\[\/stage\]/s', $content, $stage_matches, PREG_SET_ORDER);

        foreach ($stage_matches as $stage_data) {
            $stage_num = $stage_data[1];
            $stages[$stage_num] = ['number' => $stage_num, 'title' => $stage_data[2], 'source_category' => $stage_data[3] ?? null, 'categories' => [], 'cards' => []];
            preg_match_all('/\[category name="([^"]+)"\]/s', $stage_data[4], $cat_matches);
            $stages[$stage_num]['categories'] = $cat_matches[1];
            preg_match('/\[cards\](.*?)\[\/cards\]/s', $stage_data[4], $cards_content_match);
            if (!empty($cards_content_match[1])) {
                preg_match_all('/\[card(?: subscale="([^"]+)")?(?: scores="([^"]+)")?\](.*?)\[\/card\]/s', $cards_content_match[1], $card_matches, PREG_SET_ORDER);
                foreach ($card_matches as $card_data) {
                    $card = ['id' => 'card_' . uniqid(), 'subscale' => $card_data[1] ?? null, 'scores' => $card_data[2] ? explode(',', $card_data[2]) : null];
                    if (strpos($card_data[3], '[front]') !== false) {
                        preg_match('/\[front\](.*?)\[\/front\]/s', $card_data[3], $front_match);
                        preg_match('/\[back(?: type="([^"]+)")?\](.*?)\[\/back\]/s', $card_data[3], $back_match);
                        $card['front'] = trim($front_match[1] ?? '');
                        $card['back_type'] = trim($back_match[1] ?? 'بدون نوع');
                        $card['back'] = trim($back_match[2] ?? '');
                    } else {
                        $card['front'] = trim($card_data[3]);
                        $card['back_type'] = 'بدون نوع'; $card['back'] = '';
                    }
                    $stages[$stage_num]['cards'][] = $card;
                }
            }
        }
        ksort($stages);
        return array_values($stages);
    }
}