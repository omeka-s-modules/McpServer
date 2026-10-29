<?php
namespace McpServer\Mcp\Tool\Builtin;

use McpServer\Mcp\Tool\ToolException;

/**
 * Resolves what a model names in an argument, such as a class's term, a
 * template's label, or a site's slug, to the ID Omeka's API takes, among what
 * the caller can see.
 *
 * Where NameLookup answers null, this refuses, with a message that lists the
 * valid names when there are few enough, so the model can correct the
 * argument.
 */
class IdResolver
{
    // Most classes, templates, or sites an error lists, so a model can
    // correct a name.
    const NAMES_IN_ERROR_MAX = 50;

    protected $names;

    protected $counter;

    public function __construct(NameLookup $names, ResourceCounter $counter)
    {
        $this->names = $names;
        $this->counter = $counter;
    }

    /**
     * Find the class a term or label names, among the classes in use.
     *
     * @throws ToolException When nothing matches, or a label matches several
     */
    public function findClass(string $termOrLabel): int
    {
        $matches = str_contains($termOrLabel, ':')
            ? array_filter([$this->names->findUsedTerm('resource_classes', $termOrLabel)])
            : $this->names->findUsedClassesByLabel($termOrLabel);
        if (1 === count($matches)) {
            return reset($matches)['id'];
        }
        if (!$matches) {
            // get_overview lists only the classes items use, so a class used
            // only by media or item sets is named here.
            $classes = $this->names->getUsedClasses();
            if (count($classes) <= self::NAMES_IN_ERROR_MAX) {
                throw new ToolException(sprintf(
                    'Nothing uses the class "%s". The classes in use are: %s.',
                    $termOrLabel,
                    implode(', ', array_map(fn ($class) => sprintf('%s (%s)', $class['term'], $class['label']), $classes))
                ));
            }
            throw new ToolException(sprintf('Nothing uses the class "%s". Call get_overview to see the classes items use.', $termOrLabel));
        }
        throw new ToolException(sprintf(
            'The label "%s" matches several classes. Use one of their terms instead: %s.',
            $termOrLabel, implode(', ', array_column($matches, 'term'))
        ));
    }

    /**
     * Find the property a term names, among the properties in use.
     *
     * @throws ToolException When no property in use has the term
     */
    public function findProperty(string $term): int
    {
        $property = $this->names->findUsedTerm('properties', $term);
        if (!$property) {
            throw new ToolException(sprintf('No values use the property "%s". Call get_overview to see the properties in use.', $term));
        }
        return $property['id'];
    }

    /**
     * Find the resource template a label names, exactly or else ignoring
     * case.
     *
     * @throws ToolException When no template has the label
     */
    public function findTemplate(string $label): int
    {
        $labels = $this->names->getTemplateLabels();
        $id = array_search($label, $labels, true);
        if (false === $id) {
            $id = array_search(mb_strtolower($label), array_map('mb_strtolower', $labels), true);
        }
        if (false === $id) {
            if (count($labels) <= self::NAMES_IN_ERROR_MAX) {
                throw new ToolException(sprintf('There is no resource template "%s". The templates are: %s.', $label, implode(', ', $labels)));
            }
            throw new ToolException(sprintf('There is no resource template "%s". Call get_overview to see the templates items use.', $label));
        }
        return (int) $id;
    }

    /**
     * Find the site a slug names.
     *
     * @throws ToolException When no visible site has the slug
     */
    public function findSite(string $slug): int
    {
        $slugs = $this->names->getSiteSlugs();
        $siteId = array_search($slug, $slugs, true);
        if (false === $siteId) {
            if (!$slugs) {
                throw new ToolException(sprintf('There is no site "%s", and no site you can see.', $slug));
            }
            if (count($slugs) <= self::NAMES_IN_ERROR_MAX) {
                throw new ToolException(sprintf('There is no site "%s". The sites, by slug, are: %s.', $slug, implode(', ', $slugs)));
            }
            throw new ToolException(sprintf('There is no site "%s". Call get_overview to see the sites.', $slug));
        }
        return (int) $siteId;
    }

    /**
     * Check that the caller may see an item, so a filter by an item they
     * can't see says so, rather than finding nothing.
     *
     * @throws ToolException When no visible item has the id
     */
    public function findItem(int $id): int
    {
        if (!$this->counter->countResources('items', ['id' => $id])) {
            throw new ToolException(sprintf('No item with id %d exists or is visible to you.', $id));
        }
        return $id;
    }
}
