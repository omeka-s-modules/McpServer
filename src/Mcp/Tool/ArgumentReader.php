<?php
namespace McpServer\Mcp\Tool;

/**
 * Typed access to a tool's arguments.
 *
 * Models sometimes send numbers as strings, so integers are read leniently.
 * Clients often send an empty string for an argument they mean to leave out,
 * so a blank string counts as absent, for every type. Anything that can't be
 * used becomes a ToolException naming the argument.
 */
final class ArgumentReader
{
    // The largest integer accepted from a float or a string (15 digits).
    const SAFE_INT = 999999999999999;

    private $arguments;

    public function __construct(array $arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Get a string argument, or the default when it's absent.
     */
    public function getString(string $name, ?string $default = null): ?string
    {
        $value = $this->get($name);
        if (null === $value) {
            return $default;
        }
        if (!is_string($value)) {
            throw new ToolException(sprintf('The "%s" argument must be a string.', $name));
        }
        return $value;
    }

    /**
     * Get a string argument trimmed, or null when it's absent.
     */
    public function getText(string $name): ?string
    {
        $value = $this->getString($name);
        return null === $value ? null : trim($value);
    }

    /**
     * Whether an argument was given, other than as null or a blank string.
     */
    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /**
     * Get an integer argument, or the default when it's absent.
     */
    public function getInt(string $name, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->get($name);
        if (null === $value) {
            return $default;
        }
        // Convert only what fits safely. Casting a larger float or numeric
        // string to int wraps or saturates instead of failing.
        if (is_float($value) && floor($value) === $value && abs($value) <= self::SAFE_INT) {
            $value = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?\d{1,15}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new ToolException(sprintf('The "%s" argument must be an integer.', $name));
        }
        if ((null !== $min && $value < $min) || (null !== $max && $value > $max)) {
            throw new ToolException(sprintf('The "%s" argument must be %s.', $name, $this->describeRange($min, $max)));
        }
        return $value;
    }

    /**
     * Get an integer argument that must be present.
     */
    public function requireInt(string $name, ?int $min = null, ?int $max = null): int
    {
        $value = $this->getInt($name, null, $min, $max);
        if (null === $value) {
            throw new ToolException(sprintf('The "%s" argument is required.', $name));
        }
        return $value;
    }

    /**
     * Get a boolean argument, or the default when it's absent.
     */
    public function getBool(string $name, ?bool $default = null): ?bool
    {
        $value = $this->get($name);
        if (null === $value) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, 0, '1', '0', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }
        throw new ToolException(sprintf('The "%s" argument must be true or false.', $name));
    }

    /**
     * Get a list of integers, accepting a single integer too, or [] when absent.
     */
    public function getIntList(string $name, ?int $min = null, ?int $maxItems = null): array
    {
        $value = $this->get($name);
        if (null === $value) {
            return [];
        }
        $values = is_array($value) && array_is_list($value) ? $this->getList($name, $maxItems) : [$value];
        // Unlike a blank argument, a blank entry is an error: dropping it
        // would silently widen the search.
        return array_map(function ($item) use ($name, $min) {
            $value = (new self([$name => $item]))->getInt($name, null, $min);
            if (null === $value) {
                throw new ToolException(sprintf('The "%s" argument must list integers.', $name));
            }
            return $value;
        }, $values);
    }

    /**
     * Get a list argument, such as a list of conditions, or [] when absent.
     */
    public function getList(string $name, ?int $max = null): array
    {
        $value = $this->get($name);
        if (null === $value) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ToolException(sprintf('The "%s" argument must be a list.', $name));
        }
        if (null !== $max && count($value) > $max) {
            throw new ToolException(sprintf('The "%s" argument may have at most %d entries.', $name, $max));
        }
        return $value;
    }

    /**
     * Get a string argument limited to the allowed values, or the default.
     */
    public function getEnum(string $name, array $allowed, ?string $default = null): ?string
    {
        $value = $this->getText($name) ?? $default;
        if (null !== $value && !in_array($value, $allowed, true)) {
            throw new ToolException(sprintf('The "%s" argument must be one of: %s.', $name, implode(', ', $allowed)));
        }
        return $value;
    }

    /**
     * Get an argument's raw value, or null when it's absent or a blank string.
     */
    private function get(string $name)
    {
        $value = $this->arguments[$name] ?? null;
        return (is_string($value) && '' === trim($value)) ? null : $value;
    }

    private function describeRange(?int $min, ?int $max): string
    {
        if (null !== $min && null !== $max) {
            return sprintf('from %d to %d', $min, $max);
        }
        return null !== $min ? sprintf('at least %d', $min) : sprintf('at most %d', $max);
    }
}
