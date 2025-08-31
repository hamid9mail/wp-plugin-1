<?php
/**
 * Activity Class: Advanced Gravity Forms
 *
 * Version: 2.0.0
 * This class transforms a standard Gravity Form into a conversational, step-by-step experience
 * and integrates it seamlessly with the Psych mission engine. It also adds a robust
 * result retrieval system via a new shortcode.
 *
 * @implements Psych_Activity_Interface
 */
class Psych_Gform_Activity implements Psych_Activity_Interface {

    private $atts;
    private $form_id;

    public function __construct($atts, $content) {
        $this->atts = shortcode_atts([
            'form_id'               => 0,
            'id'                    => '', // Mission ID
            'sets_flag_on_complete' => '',
            'rewards'               => '',
            'allowed_actors'        => 'self',
            'required_actors'       => 1,
            'require_coach_approval'=> 'false',
            'target_user_id'        => 0
        ], $atts);
        $this->form_id = intval($this->atts['form_id']);
    }

    /**
     * Renders the Gravity Form with conversational wrapper and hooks.
     */
    public function render() {
        if (!class_exists('GFAPI') || empty($this->form_id)) {
            return '<div class="psych-error">افزونه Gravity Forms فعال نیست یا شناسه فرم مشخص نشده است.</div>';
        }

        // Add filters to inject our mission data into the form as hidden fields
        add_filter('gform_pre_render_' . $this->form_id, [$this, 'add_mission_hidden_fields']);
        add_filter('gform_form_tag', [$this, 'add_data_attributes_to_form'], 10, 2);

        $data_attrs = sprintf(
            'data-activity-id="%s" data-target-user-id="%s"',
            esc_attr($this->atts['id']),
            esc_attr($this->atts['target_user_id'])
        );

        $html = sprintf('<div class="psych-activity-container psych-gform-activity-wrapper" %s>', $data_attrs);
        $html .= do_shortcode('[gravityform id="' . $this->form_id . '" title="true" description="true" ajax="true"]');
        $html .= '</div>';

        // Remove filters after rendering to avoid conflicts
        remove_filter('gform_pre_render_' . $this->form_id, [$this, 'add_mission_hidden_fields']);
        remove_filter('gform_form_tag', [$this, 'add_data_attributes_to_form'], 10);

        return $html;
    }

    /**
     * Injects hidden fields into the form to pass mission context upon submission.
     */
    public function add_mission_hidden_fields($form) {
        // Add a master hidden field to identify this as a psych_mission
        $form['fields'][] = GF_Fields::create([
            'type' => 'hidden', 'id' => 9000, 'formId' => $form['id'],
            'inputName' => 'is_psych_mission', 'defaultValue' => 'true',
        ]);

        foreach ($this->atts as $key => $value) {
            if (in_array($key, ['form_id'])) continue;
            $form['fields'][] = GF_Fields::create([
                'type' => 'hidden', 'id' => 9001 + crc32($key),
                'formId' => $form['id'], 'inputName' => 'psych_mission_' . $key, 'defaultValue' => $value,
            ]);
        }
        return $form;
    }

    public function add_data_attributes_to_form($form_tag, $form) {
        if ($form['id'] == $this->form_id) {
             return str_replace(' action=', ' class="psych-conversational-form" action=', $form_tag);
        }
        return $form_tag;
    }

    public function get_css() {
        return '
        .psych-gform-activity-wrapper .gform_wrapper { margin: 0; padding: 0; }
        .modern-form-container { background: white; border-radius: 16px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); padding: 30px; margin: 30px auto; max-width: 800px; position: relative; overflow: hidden; }
        .modern-form-container::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 6px; background: linear-gradient(to right, #2196F3, #03A9F4, #00BCD4); }
        .conv-navigation { margin: 20px 0; display: flex; gap: 10px; justify-content: center; }
        .conv-button { background: #2196F3; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: all 0.2s; }
        .psych-conversational-form .gform_footer { display: none !important; }
        .psych-conversational-form.final-step .gform_footer { display: flex !important; margin-top: 20px; justify-content: center; }
        .gform_wrapper.psych-conversational-form .gfield_animation { animation: fadeSlideIn 0.4s ease-out; }
        @keyframes fadeSlideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .conv-progress-container { margin-bottom: 30px; padding: 10px; border-radius: 10px; background: rgba(240,240,240,0.6); }
        .conv-progress-bar { height: 8px; background: linear-gradient(to right, #2196F3, #03A9F4); border-radius: 10px; transition: width 0.3s; }
        .gfield_html, .gsection { background: #f4f9fd; border-radius: 8px; padding: 18px; margin-bottom: 12px; }
        .gsection .gsection_title { font-weight: bold; color: #306195; font-size: 18px; }
        ';
    }

    public function get_javascript() {
        return "
        (function($){
            $(document).on('gform_post_render', function(event, formId, currentPage){
                const wrapper = $('#gform_wrapper_' + formId);
                if (!wrapper.hasClass('psych-conversational-form')) return;

                if (!wrapper.parent().hasClass('modern-form-container')) {
                    wrapper.wrap('<div class=\"modern-form-container\"></div>');
                }

                const allFields = wrapper.find('.gfield').filter(function() {
                    return $(this).css('display') !== 'none' && !$(this).is('.gform_hidden, .gform_validation_container');
                });
                if (allFields.length === 0) return;

                let steps = [], currentGroup = [];
                allFields.each(function(i, field){
                    const isInteractive = $(field).find('input:not([type=\"hidden\"]), select, textarea').length > 0;
                    currentGroup.push($(field));
                    if(isInteractive) { steps.push(currentGroup); currentGroup = []; }
                });
                if(currentGroup.length > 0) steps.push(currentGroup);

                let currentStep = 0;
                const totalSteps = steps.length;

                if (wrapper.find('.conv-progress-container').length === 0) {
                    wrapper.prepend('<div class=\"conv-progress-container\"><div class=\"conv-progress-bar\" style=\"width:0%;\"></div></div>');
                }
                wrapper.find('.gform_body').after('<div class=\"conv-navigation\"></div>');

                function showStep(n) {
                    allFields.hide();
                    steps[n].forEach(field => field.show().addClass('gfield_animation'));
                    const progress = ((n + 1) / totalSteps) * 100;
                    wrapper.find('.conv-progress-bar').css('width', progress + '%');
                    updateNavigation(n);
                    $('html, body').animate({ scrollTop: wrapper.closest('.modern-form-container').offset().top - 50 }, 300);
                }

                function updateNavigation(n) {
                    const nav = wrapper.find('.conv-navigation').empty();
                    if (n > 0) {
                        $('<button type=\"button\" class=\"conv-button conv-prev\">قبلی</button>').on('click', () => gotoStep(n - 1)).appendTo(nav);
                    }
                    if (n < totalSteps - 1) {
                        $('<button type=\"button\" class=\"conv-button conv-next\">بعدی</button>').on('click', () => gotoStep(n + 1)).appendTo(nav);
                        wrapper.removeClass('final-step');
                    } else {
                        wrapper.addClass('final-step');
                    }
                }

                function gotoStep(n) {
                    if (n < 0 || n >= totalSteps) return;
                    currentStep = n;
                    showStep(currentStep);
                }

                steps.forEach((group, groupIndex) => {
                    group.forEach(field => {
                        field.find('input[type=\"radio\"]').on('change', () => {
                            setTimeout(() => { if (groupIndex === currentStep && currentStep < totalSteps - 1) gotoStep(currentStep + 1); }, 350);
                        });
                    });
                });

                showStep(0);
            });

            // This event triggers after successful AJAX submission
            $(document).on('gform_confirmation_loaded', function(event, formId){
                const wrapper = $('#gform_wrapper_' + formId);
                if (wrapper.hasClass('psych-conversational-form')) {
                    const missionContainer = wrapper.closest('.psych-gform-activity-wrapper');
                    const activityId = missionContainer.data('activity-id');
                    // Show mission success feedback
                    const feedbackEl = missionContainer.find('.psych-activity-feedback');
                    if (feedbackEl.length === 0) {
                         missionContainer.append('<div class=\"psych-activity-feedback success\" style=\"margin-top:20px;\">فرم شما با موفقیت ارسال شد! در حال پردازش نتایج...</div>');
                    } else {
                        feedbackEl.removeClass('error').addClass('success').html('فرم با موفقیت ارسال شد!').slideDown();
                    }
                    setTimeout(() => { window.location.reload(); }, 2000);
                }
            });

        })(jQuery);
        ";
    }
}
