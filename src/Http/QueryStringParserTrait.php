<?php

namespace Percy\Http;

use InvalidArgumentException;

trait QueryStringParserTrait
{
    /**
     * @var integer
     */
    protected $param = 0;

    /**
     * Parse HTTP query string and return array representation
     * to be attached to a database query.
     *
     * @param string $query
     *
     * @return array
     */
    public function parseQueryString($query)
    {
        if (empty($query)) {
            return [];
        }

        parse_str($query, $split);

        $query = [];

        while (list($key, $value) = each($split)) {
            $mapped = call_user_func_array([$this, 'filterQueryParams'], [$key, $value]);
            if ($mapped !== false) {
                $query[$key] = $mapped;
            }
        }

        return $query;
    }

    /**
     * Map the parsed query string in to correct array structure.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return array|boolean
     */
    protected function filterQueryParams($key, $value)
    {
        switch ($key) {
            case 'limit':
            case 'offset':
                return (int) $value;
            case 'sort':
                return $this->parseSort($value);
            case 'filter':
                return $this->parseFilters((array) $value);
            case 'has':
                return explode(',', $value);
            case 'include':
                return $this->parseInclude(explode(',', $value));
            case 'search':
                return $this->parseSearch($value);
            case 'minscore':
                return (float) $value;
            default:
                return false;
        }
    }

    /**
     * Map sorts to a usable format.
     *
     * @param string $value
     *
     * @return array
     */
    protected function parseSort($value)
    {
        $map   = [];
        $sorts = explode(',', $value);

        foreach ($sorts as $sort) {
            $sort      = explode('|', $sort);
            $direction = (count($sort) > 1) ? $sort[1] : 'asc';

            if (in_array($sort[0], ['rand', 'random'])) {
                return 'RAND()';
            }

            $map[] = [
                'field'     => $sort[0],
                'direction' => $direction
            ];
        }

        return $map;
    }

    /**
     * Map search to a usable format.
     *
     * @param string $value
     *
     * @return array
     */
    protected function parseSearch($value)
    {
        $search = explode('|', $value);

        if (count($search) !== 2) {
            throw new InvalidArgumentException(
                'Malformed query string, search format should be (search=field|term) or (search=field1,field2|term)'
            );
        }

        return [
            'fields' => $search[0],
            'term'   => $search[1]
        ];
    }

    /**
     * Map filters in to useable array.
     *
     * @param array $filters
     *
     * @return array
     */
    protected function parseFilters(array $filters)
    {
        $mapped = [];

        $this->param = 0;

        foreach ($filters as $filter) {
            $mapped[] = $this->parseFilter($filter);
        }

        return $mapped;
    }

    /**
     * Parse an individual filter.
     *
     * @param string $filter
     *
     * @return array
     */
    protected function parseFilter($filter)
    {
        $filter = explode('|', $filter);

        if (count($filter) !== 3) {
            throw new InvalidArgumentException(
                'Malformed query string, filter format should be (filter[]=field|delimiter|value)'
            );
        }

        $filter = array_combine(['field', 'delimiter', 'value'], $filter);

        $filter['binding']   = str_replace('.', '_', $filter['field']) . '_' . $this->param++;
        $filter['delimiter'] = strtolower($filter['delimiter']);
        $filter['delimiter'] = html_entity_decode($filter['delimiter']);

        if (! in_array($filter['delimiter'], [
            '=', '!=', '<>', '<=', '>=', '<', '>', 'in', 'not in', 'like', 'not like'
        ])) {
            throw new InvalidArgumentException(sprintf('(%s) is not an accepted delimiter', $filter['delimiter']));
        }

        return $filter;
    }

    /**
     * Map includes in to useable array.
     *
     * @param array $includes
     *
     * @return array
     */
    protected function parseInclude(array $includes)
    {
        $mapped = [];

        foreach ($includes as $include) {
            $parts = explode(';', $include);
            $key   = array_shift($parts);

            if (empty($parts)) {
                $mapped[$key] = [];
                continue;
            }

            $mapped[$key]['filter'] = [];
            $mapped[$key]['limit']  = null;

            foreach ($parts as $part) {
                $split = explode('|', $part);

                switch (count($split)) {
                    case 2:
                        $mapped[$key]['limit'] = $split[1];
                    break;
                    case 3:
                        $mapped[$key]['filter'][] = $this->parseFilter($part);
                    break;
                    default:
                        throw new InvalidArgumentException('Include formatted incorrectly.');
                }
            }
        }

        return $mapped;
    }
}
