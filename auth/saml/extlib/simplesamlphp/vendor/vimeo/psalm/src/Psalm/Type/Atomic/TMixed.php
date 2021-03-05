<?php
namespace Psalm\Type\Atomic;

class TMixed extends \Psalm\Type\Atomic
{
    /** @var bool */
    public $from_isset = false;

    /**
     * @param bool $from_isset
     */
    public function __construct($from_isset = false)
    {
        $this->from_isset = $from_isset;
    }

    public function __toString()
    {
        return 'mixed';
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return 'mixed';
    }

    /**
     * @param  string|null   $namespace
     * @param  array<string> $aliased_classes
     * @param  string|null   $this_class
     * @param  int           $php_major_version
     * @param  int           $php_minor_version
     *
     * @return null|string
     */
    public function toPhpString(
        $namespace,
        array $aliased_classes,
        $this_class,
        $php_major_version,
        $php_minor_version
    ) {
        return null;
    }

    public function canBeFullyExpressedInPhp()
    {
        return false;
    }
}
