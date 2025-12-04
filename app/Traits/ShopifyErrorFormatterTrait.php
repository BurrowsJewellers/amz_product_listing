<?php

namespace App\Traits;

/**
 * Trait for formatting Shopify GraphQL error messages
 *
 * Used by Shopify commands that need to format error responses
 * from GraphQL API calls.
 */
trait ShopifyErrorFormatterTrait
{
    /**
     * Format error message from GraphQL result
     *
     * @param  array  $result  The GraphQL response array
     * @return string Formatted error message
     */
    protected function formatGraphQLErrorMessage(array $result): string
    {
        if (! empty($result['user_errors'])) {
            return $this->formatUserErrors($result['user_errors']);
        }

        if (! empty($result['userErrors'])) {
            return $this->formatUserErrors($result['userErrors']);
        }

        if (! empty($result['graphql_errors'])) {
            return json_encode($result['graphql_errors']);
        }

        if (! empty($result['errors'])) {
            return json_encode($result['errors']);
        }

        return 'Unknown error';
    }

    /**
     * Format user errors array into readable string
     *
     * @param  array  $userErrors  Array of user errors
     * @return string Formatted error string
     */
    protected function formatUserErrors(array $userErrors): string
    {
        $messages = [];

        foreach ($userErrors as $error) {
            $message = $error['message'] ?? 'Unknown error';
            if (isset($error['field'])) {
                $field = is_array($error['field']) ? implode('.', $error['field']) : $error['field'];
                $message = "[{$field}] {$message}";
            }
            $messages[] = $message;
        }

        return implode(' | ', $messages);
    }

    /**
     * Check if error indicates the product/variant no longer exists on Shopify
     *
     * @param  string  $errorMessage  The error message to check
     * @return bool True if the error indicates a missing resource
     */
    protected function isResourceNotExistsError(string $errorMessage): bool
    {
        $notExistsPatterns = [
            'Product does not exist',
            'Product not found',
            'does not exist',
            'could not be found',
            'Couldn\'t find',
            'Resource not found',
        ];

        foreach ($notExistsPatterns as $pattern) {
            if (stripos($errorMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
