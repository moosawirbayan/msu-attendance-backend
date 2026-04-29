<?php
/**
 * Validator Class
 * Input validation helper
 */

class Validator {
    private $errors = [];

    /**
     * Validate required field
     */
    public function required($value, $fieldName) {
        if (empty($value) && $value !== '0') {
            $this->errors[$fieldName] = "$fieldName is required";
            return false;
        }
        return true;
    }

    /**
     * Validate email
     */
    public function email($value, $fieldName) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$fieldName] = "Invalid email format";
            return false;
        }
        return true;
    }

    /**
     * Validate minimum length
     */
    public function minLength($value, $length, $fieldName) {
        if (strlen($value) < $length) {
            $this->errors[$fieldName] = "$fieldName must be at least $length characters";
            return false;
        }
        return true;
    }

    /**
     * Validate MSU institutional email
     */
    public function msuEmail($value, $fieldName) {
        if (!str_contains($value, '@msuiit.edu.ph')) {
            $this->errors[$fieldName] = "Please use your MSU institutional email (@msuiit.edu.ph)";
            return false;
        }
        return true;
    }

    /**
     * Check if validation passed
     */
    public function passes() {
        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    public function getErrors() {
        return $this->errors;
    }
}
