<?php

namespace Phabalicious\Scaffolder;

use Phabalicious\ShellProvider\ShellProviderInterface;
use Phabalicious\Utilities\Utilities;

class Options extends CallbackOptions
{

    protected $allowOverride = false;

    protected $pluginRegistrationCallback = null;

    protected $compabilityVersion = Utilities::FALLBACK_VERSION;

    protected $dynamicOptions = [];

    protected $skipSubfolder = false;

    protected $useCacheTokens = true;

    protected $dryRun = false;

    protected $quiet = false;

    protected $variables = [];

    protected $baseUrl = false;

    /** @var ShellProviderInterface */
    protected $shell = null;

    /** @var array */
    protected $definition = null;

    protected $twigLoaderBase = null;

    public function getAllowOverride(): bool
    {
        return $this->allowOverride;
    }


    /**
     * @param callable $pluginRegistrationCallback
     * @return Options
     */
    public function setPluginRegistrationCallback(callable $pluginRegistrationCallback): Options
    {
        $this->pluginRegistrationCallback = $pluginRegistrationCallback;
        return $this;
    }

    /**
     * @param bool $allowOverride
     * @return Options
     */
    public function setAllowOverride(bool $allowOverride): Options
    {
        $this->allowOverride = $allowOverride;
        return $this;
    }

    /**
     * @return callable|null
     */
    public function getPluginRegistrationCallback() : ?callable
    {
        return $this->pluginRegistrationCallback;
    }

    /**
     * @param string $compabilityVersion
     * @return Options
     */
    public function setCompabilityVersion(string $compabilityVersion): Options
    {
        $this->compabilityVersion = $compabilityVersion;
        return $this;
    }

    /**
     * @return string
     */
    public function getCompabilityVersion(): string
    {
        return $this->compabilityVersion;
    }

    public function getDynamicOption(string $option_name)
    {
        return $this->dynamicOptions[$option_name] ?? false;
    }

    /**
     * @param array $dynamic_options
     * @return Options
     */
    public function setDynamicOptions(array $dynamic_options): Options
    {
        $this->dynamicOptions = $dynamic_options;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSkipSubfolder() : bool
    {
        return $this->skipSubfolder;
    }

    /**
     * @param mixed $skipSubfolder
     * @return Options
     */
    public function setSkipSubfolder($skipSubfolder): Options
    {
        $this->skipSubfolder = $skipSubfolder;
        return $this;
    }


    /**
     * @param bool $useCacheTokens
     * @return Options
     */
    public function setUseCacheTokens(bool $useCacheTokens): Options
    {
        $this->useCacheTokens = $useCacheTokens;
        return $this;
    }

    /**
     * @return bool
     */
    public function useCacheTokens(): bool
    {
        return $this->useCacheTokens;
    }

    /**
     * @param $key
     *  The key name of the variable.
     * @param $value
     *  The value of the variable.
     * @return Options
     */
    public function addVariable($key, $value): Options
    {
        $this->variables[$key] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * @param ShellProviderInterface $shell
     * @return Options
     */
    public function setShell(ShellProviderInterface $shell): Options
    {
        $this->shell = $shell;
        return $this;
    }

    /**
     * @return ShellProviderInterface
     */
    public function getShell(): ?ShellProviderInterface
    {
        return $this->shell;
    }

    /**
     * Set dry-run flag.
     *
     * @param bool $flag
     *
     * @return $this
     */
    public function setDryRun(bool $flag): Options
    {
        $this->dryRun = $flag;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @return bool
     */
    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    /**
     * @param bool $quiet
     *
     * @return \Phabalicious\Scaffolder\Options
     */
    public function setQuiet(bool $quiet): Options
    {
        $this->quiet = $quiet;
        return $this;
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * @param mixed $baseUrl
     *
     * @return Options
     */
    public function setBaseUrl($baseUrl): Options
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function getDynamicOptions(): array
    {
        return $this->dynamicOptions;
    }

    /**
     * @return null|array
     */
    public function getScaffoldDefinition(): ?array
    {
        return $this->definition;
    }

    /**
     * @param array $definition
     *
     * @return Options
     */
    public function setScaffoldDefinition(array $definition): Options
    {
        $this->definition = $definition;
        return $this;
    }

    public function getTwigLoaderBase(): ?string
    {
        return $this->twigLoaderBase;
    }

    /**
     * @param mixed $twigLoaderBase
     *
     * @return Options
     */
    public function setTwigLoaderBase($twigLoaderBase)
    {
        $this->twigLoaderBase = $twigLoaderBase;
        return $this;
    }
}
