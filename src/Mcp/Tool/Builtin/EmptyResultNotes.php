<?php
namespace McpServer\Mcp\Tool\Builtin;

use Laminas\Authentication\AuthenticationService;
use McpServer\Mcp\Format\ResourceFormatter;
use McpServer\Mcp\Query\NameLookup;
use McpServer\Mcp\Query\ResourceCounter;

/**
 * Says why a search or count of items, media, or item sets matched nothing,
 * in a note that tells the model what to try instead.
 *
 * It runs only when nothing matched, so the counts some notes make cost
 * nothing otherwise.
 */
class EmptyResultNotes
{
    protected $names;

    protected $counter;

    protected $auth;

    public function __construct(NameLookup $names, ResourceCounter $counter, AuthenticationService $auth)
    {
        $this->names = $names;
        $this->counter = $counter;
        $this->auth = $auth;
    }

    /**
     * Say why a query may have matched nothing, for a tool's note.
     *
     * @param string $resource "items", "media", or "item_sets"
     * @param array $query An API query, as SearchFilters builds it
     */
    public function explainEmpty(string $resource, array $query): string
    {
        $noun = SearchFilters::noun($resource);
        // A condition on a property used only in annotations can't match,
        // since core searches the values of resources, not of annotations.
        $terms = $this->names->getAnnotationOnlyTerms(array_column($query['property'] ?? [], 'property'));
        if ($terms) {
            return sprintf(
                'No %s matched. %s %s used only in value annotations, which "where" can\'t search. count_values on %s names the properties whose values carry those annotations, and get_item with "property" set to one of them shows each value with its annotations.',
                $noun, implode(' and ', $terms), count($terms) > 1 ? 'are' : 'is', $terms[0]
            );
        }
        $missing = $this->explainMissingProperties($resource, $query);
        if ($missing) {
            return $missing;
        }
        if (isset($query['fulltext_search'])) {
            // MySQL's full-text index skips stopwords and short words, so a
            // search for "the" finds nothing rather than everything.
            return sprintf(
                'No %s matched. Full-text search ignores very common words and words shorter than three letters, so if the query was made only of those, try more specific words, or "contains". Otherwise the installation may have nothing on this.',
                $noun
            );
        }
        if (isset($query['is_public']) && !$query['is_public']) {
            // Signed in, the caller sees what the key's user may; without a
            // key, no private resources at all.
            return $this->auth->hasIdentity()
                ? sprintf('No private %s this key\'s user may see matched.', $noun)
                : sprintf('No %1$s matched. Without an MCP key, no private %1$s are visible, so there may be some all the same.', $noun);
        }
        return sprintf('No %s matched. Loosen the filters, or check them against get_overview.', $noun);
    }

    /**
     * Say which "where" condition can't match because none of the records
     * that meet the rest of the query has its property, naming the date
     * properties they use when the missing one is a date.
     *
     * Records keep dates in different properties, so a condition on the wrong
     * one is a common dead end. It counts once per condition, so it runs only
     * when nothing matched.
     */
    protected function explainMissingProperties(string $resource, array $query): ?string
    {
        $query = array_diff_key($query, ['page' => true, 'per_page' => true]);
        $conditions = $query['property'] ?? [];
        foreach ($conditions as $index => $condition) {
            $term = 'nex' === $condition['type'] ? null : $this->names->getPropertyTerm($condition['property']);
            if (null === $term) {
                continue;
            }
            $rest = $query;
            unset($rest['property'][$index]);
            $rest['property'] = array_values($rest['property']);
            if ($this->countHaving($resource, $rest, $condition['property'])) {
                continue;
            }
            $noun = SearchFilters::noun($resource);
            $note = sprintf(
                'No %1$s matched: none of the %1$s that meet the rest of the query has a value for %2$s, so that condition can\'t match.',
                $noun, $term
            );
            if (!in_array($term, ResourceFormatter::DATE_TERMS, true)) {
                return $note . ' count_properties lists the properties they have.';
            }
            $used = [];
            foreach (array_diff(ResourceFormatter::DATE_TERMS, [$term]) as $dateTerm) {
                $property = $this->names->findUsedTerm('properties', $dateTerm);
                $count = $property ? $this->countHaving($resource, $rest, $property['id']) : 0;
                if ($count) {
                    $used[] = sprintf('%s (%d of them)', $dateTerm, $count);
                }
            }
            return $used ? sprintf('%s They keep their dates in %s.', $note, implode(' and ', $used)) : $note;
        }
        return null;
    }

    /**
     * Count the resources that match a query and have a value of a property.
     */
    protected function countHaving(string $resource, array $query, int $propertyId): int
    {
        $query['property'][] = ['joiner' => 'and', 'property' => $propertyId, 'type' => 'ex'];
        return $this->counter->countResources($resource, $query);
    }
}
