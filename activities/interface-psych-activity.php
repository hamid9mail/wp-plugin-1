<?php
/**
 * Interface Psych_Activity_Interface
 * Defines the contract that all activity classes must follow.
 */
interface Psych_Activity_Interface {

    /**
     * The constructor receives the shortcode attributes and content.
     */
    public function __construct($atts, $content);

    /**
     * This method must be implemented by every activity.
     * It is responsible for generating the final HTML of the activity.
     * @return string The HTML output.
     */
    public function render();
}