<?php


namespace Phabalicious\Validation;

use Phabalicious\Validation\ValidationErrorBagInterface;

class ValidationService
{

    private $config;
    private $errors;
    private $prefixMessage;

    /**
     * ValidationService constructor.
     *
     * @param array $config
     * @param \Phabalicious\Validation\ValidationErrorBagInterface $errors
     * @param string $prefix_message
     */
    public function __construct(array $config, ValidationErrorBagInterface $errors, string $prefix_message)
    {
        $this->config = $config;
        $this->errors = $errors;
        $this->prefixMessage = $prefix_message;
    }

    public function getErrorBag() : ValidationErrorBagInterface
    {
        return $this->errors;
    }
    public function hasKey(string $key, string $message): bool
    {
        if (!isset($this->config[$key])) {
            $this->errors->addError($key, 'Missing key '. $key . ' in ' . $this->prefixMessage . ': ' . $message);
            return false;
        }
        return true;
    }

    public function hasKeys(array $keys)
    {
        foreach ($keys as $key => $message) {
            $this->hasKey($key, $message);
        }
    }

    public function deprecate(array $keys)
    {
        foreach ($keys as $key => $message) {
            if (isset($this->config[$key])) {
                $this->errors->addWarning($key, $message);
            }
        }
    }

    public function arrayContainsKey(string $key, array $haystack, string $message)
    {
        if (!isset($haystack[$key])) {
            $this->errors->addError($key, 'key '. $key . ' not found. ' . $this->prefixMessage . ': ' . $message);
        }
    }
    public function isArray(string $key, string $message)
    {
        if ($this->hasKey($key, $message) && !is_array($this->config[$key])) {
            $this->errors->addError($key, 'key '. $key . ' not an array in ' . $this->prefixMessage . ': ' . $message);
        }
    }

    public function isOneOf(string $key, array $candidates)
    {
        if ($this->hasKey($key, 'Candidates: ' . implode(', ', $candidates))
            && !in_array($this->config[$key], $candidates)) {
            $this->errors->addError(
                $key,
                'key '. $key . ' has unrecognized value: ' .
                $this->config[$key] . ' in ' . $this->prefixMessage . ': Candidates are ' . implode(', ', $candidates)
            );
        }
    }

    public function checkForValidFolderName(string $key)
    {
        if (!$this->hasKey($key, 'Missing key')) {
            return false;
        }
        if ($this->config[$key] !== '/' && substr($this->config[$key], -1) === DIRECTORY_SEPARATOR) {
            $this->errors->addError(
                $key,
                sprintf('key %s is ending with a directory separator, please change!', $key)
            );
            return false;
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getNewValidatorFor(string $key)
    {
        $this->isArray($key, 'Sub-config needs to be an array');
        if (is_array($this->config[$key])) {
            return new ValidationService($this->config[$key], $this->getErrorBag(), $this->prefixMessage);
        }

        return false;
    }

    public function hasAtLeast(array $keys, string $message)
    {

        $has_key = false;
        foreach ($keys as $key) {
            if (isset($this->config[$key])) {
                $has_key = true;
            }
        }

        if (!$has_key) {
            $this->errors->addError(implode(', ', $keys), $message);
        }

        return $has_key;
    }
}
