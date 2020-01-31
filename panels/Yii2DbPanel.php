<?php

/**
 * @author  Roman Zhuravlev <zhuravljov@gmail.com>
 *
 * @since   1.1.13
 */
class Yii2DbPanel extends Yii2DebugPanel
{
    /**
     * @var bool вставлять или нет значения параметров в sql-запрос
     */
    public $insertParamValues = true;

    /**
     * @var bool разрешен или нет explain для sql-запросов
     */
    public $canExplain = true;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $this->_logsEnabled    = true;
        $this->_logsLevels     = CLogger::LEVEL_PROFILE;
        $this->_logsCategories = 'system.db.CDbCommand.*';
        parent::init();
    }

    public function getName()
    {
        return 'Database';
    }

    public function getSummary()
    {
        $timings = $this->calculateTimings();
        $count   = \count($timings);
        $time    = 0;
        foreach ($timings as $timing) {
            $time += $timing[4];
        }
        if (!$count) {
            return '';
        }

        return $this->render(\dirname(__FILE__) . '/../views/panels/db_bar.php', [
            'count' => $count,
            'time'  => \number_format($time * 1000) . ' ms',
        ]);
    }

    public function getDetail()
    {
        return $this->render(\dirname(__FILE__) . '/../views/panels/db.php', [
            'queries'          => $this->getQueriesInfo(),
            'queriesCount'     => \count($this->calculateTimings()),
            'resume'           => $this->getResumeInfo(),
            'resumeCount'      => \count($this->calculateResume()),
            'connectionsCount' => \count($this->data['connections']),
            'connections'      => $this->getConnectionsInfo(),
        ]);
    }

    /**
     * @return array
     */
    protected function getQueriesInfo()
    {
        $items = [];
        foreach ($this->calculateTimings() as $timing) {
            $items[] = [
                'time'      => \date('H:i:s.', $timing[3]) . \sprintf('%03d',
                        (int) (($timing[3] - (int) $timing[3]) * 1000)),
                'duration'  => \sprintf('%.1f ms', $timing[4] * 1000),
                'procedure' => $this->formatSql($timing[1], $this->insertParamValues),
            ];
        }

        return $items;
    }

    /**
     * @return array
     */
    protected function getResumeInfo()
    {
        $items = [];
        foreach ($this->calculateResume() as $item) {
            $items[] = [
                'procedure' => $item[0],
                'count'     => $item[1],
                'total'     => \sprintf('%.1f ms', $item[2] * 1000),
                'avg'       => \sprintf('%.1f ms', $item[2] * 1000 / $item[1]),
                'min'       => \sprintf('%.1f ms', $item[3] * 1000),
                'max'       => \sprintf('%.1f ms', $item[4] * 1000),
            ];
        }

        return $items;
    }

    /**
     * @return array
     */
    protected function getConnectionsInfo()
    {
        $connections = [];
        foreach ($this->data['connections'] as $id => $connection) {
            if ('mysql' === $connection['driver'] && isset($connection['info'])) {
                foreach (\explode('  ', $connection['info']) as $line) {
                    list($key, $value) = \explode(': ', $line, 2);
                    $connection[$key]  = $value;
                }
                unset($connection['info']);
            }
            $connections[$id] = $connection;
        }

        return $connections;
    }

    private $_timings;

    /**
     * Группировка времени выполнения sql-запросов.
     *
     * @return array
     */
    protected function calculateTimings()
    {
        if (null !== $this->_timings) {
            return $this->_timings;
        }
        $messages = $this->data['messages'];
        $timings  = [];
        $stack    = [];
        foreach ($messages as $i => $log) {
            list($token, , $category, $timestamp) = $log;
            $log[4]                               = $i;
            if (0 === \mb_strpos($token, 'begin:')) {
                $log[0]  = $token = \mb_substr($token, 6);
                $stack[] = $log;
            } elseif (0 === \mb_strpos($token, 'end:')) {
                $log[0] = $token = \mb_substr($token, 4);
                if (null !== ($last = \array_pop($stack)) && $last[0] === $token) {
                    $timings[$last[4]] = [\count($stack), $token, $category, $last[3], $timestamp - $last[3]];
                }
            }
        }
        $now = \microtime(true);
        while (null !== ($last = \array_pop($stack))) {
            $delta             = $now - $last[3];
            $timings[$last[4]] = [\count($stack), $last[0], $last[2], $last[3], $delta];
        }
        \ksort($timings);

        return $this->_timings = $timings;
    }

    private $_resume;

    /**
     * Группировка sql-запросов.
     *
     * @return array
     */
    protected function calculateResume()
    {
        if (null !== $this->_resume) {
            return $this->_resume;
        }
        $resume = [];
        foreach ($this->calculateTimings() as $timing) {
            $duration = $timing[4];
            $query    = $this->formatSql($timing[1], $this->insertParamValues);
            $key      = \md5($query);
            if (!isset($resume[$key])) {
                $resume[$key] = [$query, 1, $duration, $duration, $duration];
            } else {
                ++$resume[$key][1];
                $resume[$key][2] += $duration;
                if ($resume[$key][3] > $duration) {
                    $resume[$key][3] = $duration;
                }
                if ($resume[$key][4] < $duration) {
                    $resume[$key][4] = $duration;
                }
            }
        }
        \usort($resume, [$this, 'compareResume']);

        return $this->_resume = $resume;
    }

    private function compareResume($a, $b)
    {
        if ($a[2] === $b[2]) {
            return 0;
        }

        return $a[2] < $b[2] ? 1 : -1;
    }

    /**
     * Выделение sql-запроса из лога и подстановка параметров.
     *
     * @param string $message
     * @param bool   $insertParams
     *
     * @return string
     */
    public function formatSql($message, $insertParams)
    {
        $sqlStart = \mb_strpos($message, '(') + 1;
        $sqlEnd   = \mb_strrpos($message, ')');
        $sql      = \mb_substr($message, $sqlStart, $sqlEnd - $sqlStart);
        if (false !== \mb_strpos($sql, '. Bound with ')) {
            list($query, $params) = \explode('. Bound with ', $sql);
            if (!$insertParams) {
                return $query;
            }
            $sql = $this->insertParamsToSql($query, $this->parseParamsSql($params));
        }

        return $sql;
    }

    /**
     * Парсинг строки с параметрами типа (:xxx, ?).
     *
     * @param string $params
     *
     * @return array key/value
     */
    private function parseParamsSql($params)
    {
        $binds = [];
        $pos   = 0;
        while (\preg_match('/((?:\:[a-z0-9\.\_\-]+)|\d+)\s*\=\s*/i', $params, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1] + \mb_strlen($m[0][0]);
            $key   = $m[1][0];
            if (('"' === $params[$start]) || ("'" === $params[$start])) {
                $quote = $params[$start];
                $pos   = $start;
                while (false !== ($pos = \mb_strpos($params, $quote, $pos + 1))) {
                    $slashes = 0;
                    while ('\\' === $params[$pos - $slashes - 1]) {
                        ++$slashes;
                    }
                    if (0 === $slashes % 2) {
                        $binds[$key] = \mb_substr($params, $start, $pos - $start + 1);
                        ++$pos;
                        break;
                    }
                }
            } elseif (false !== ($end = \mb_strpos($params, ',', $start + 1))) {
                $binds[$key] = \mb_substr($params, $start, $end - $start);
                $pos         = $end + 1;
            } else {
                $binds[$key] = \mb_substr($params, $start, \mb_strlen($params) - $start);
                break;
            }
        }

        return $binds;
    }

    /**
     * Умная подстановка параметров в SQL-запрос.
     *
     * Поиск параметров производится за пределами строк в кавычках ["'`].
     * Значения подставляются для параметров типа (:xxx, ?).
     *
     * @param string $query
     * @param array  $params
     *
     * @return string
     */
    private function insertParamsToSql($query, $params)
    {
        $sql = '';
        $pos = 0;
        do {
            // Выявление ближайшей заэкранированной части строки
            $quote = '';
            if (\preg_match('/[`"\']/', $query, $m, PREG_OFFSET_CAPTURE, $pos)) {
                $qchar  = $m[0][0];
                $qbegin = $m[0][1];
                $qend   = $qbegin;
                do {
                    $sls = 0;
                    if (false !== ($qend = \mb_strpos($query, $qchar, $qend + 1))) {
                        while ('\\' === $query[$qend - $sls - 1]) {
                            ++$sls;
                        }
                    } else {
                        $qend = \mb_strlen($query) - 1;
                    }
                } while ($sls % 2);
                $quote = \mb_substr($query, $qbegin, $qend - $qbegin + 1);
                $token = \mb_substr($query, $pos, $qbegin - $pos);
                $pos   = $qend + 1;
            } else {
                $token = \mb_substr($query, $pos);
            }
            // Подстановка параметров в незаэкранированную часть SQL
            $subsql = '';
            $pind   = 0;
            $tpos   = 0;
            while (\preg_match('/\:[a-z0-9\.\_\-]+|\?/i', $token, $m, PREG_OFFSET_CAPTURE, $tpos)) {
                $key = $m[0][0];
                if ('?' === $key) {
                    $key = $pind++;
                }
                if (isset($params[$key])) {
                    $value = $params[$key];
                } else {
                    $value = $m[0][0];
                }
                $subsql .= \mb_substr($token, $tpos, $m[0][1] - $tpos) . $value;
                $tpos   = $m[0][1] + \mb_strlen($m[0][0]);
            }
            $subsql .= \mb_substr($token, $tpos);
            // Склейка
            $sql .= $subsql . $quote;
        } while ('' !== $quote);

        return $sql;
    }

    /**
     * @var CTextHighlighter
     */
    private $_hl;

    /**
     * Подсветка sql-кода.
     *
     * @param string $sql
     *
     * @return string
     */
    public function highlightSql($sql)
    {
        if (null === $this->_hl) {
            $this->_hl = Yii::createComponent([
                'class'           => 'CTextHighlighter',
                'language'        => 'sql',
                'showLineNumbers' => false,
            ]);
        }
        $html = $this->_hl->highlight($sql);

        return \strip_tags($html, '<div>,<span>');
    }

    public function save()
    {
        $connections = [];
        foreach (Yii::app()->getComponents() as $id => $component) {
            if ($component instanceof CDbConnection) {
                $connections[$id] = [
                    'class'  => \get_class($component),
                    'driver' => $component->getDriverName(),
                ];

                try {
                    $connections[$id]['server'] = $component->getServerVersion();
                    $connections[$id]['info']   = $component->getServerInfo();
                } catch (Exception $e) {
                }
            }
        }

        return [
            'messages'    => $this->getLogs(),
            'connections' => $connections,
        ];
    }

    /**
     * Return explain procedure or null.
     *
     * @param string $query
     * @param string $driver name
     *
     * @return null|string
     */
    public function getExplainQuery($query, $driver)
    {
        if (\preg_match('/^\s*SELECT/', $query)) {
            switch ($driver) {
                case 'mysql':
                    return 'EXPLAIN ' . $query;
                case 'pgsql':
                    return 'EXPLAIN ' . $query;
                case 'sqlite':
                    return 'EXPLAIN QUERY PLAN ' . $query;
                case 'oci':
                    return 'EXPLAIN PLAN FOR ' . $query;
            }
        }

        return null;
    }

    /**
     * Run explain procedure.
     *
     * @param string        $query
     * @param CDbConnection $connection
     *
     * @return array
     */
    public function explain($query, $connection)
    {
        $procedure = $this->getExplainQuery($query, $connection->driverName);
        if (null === $procedure) {
            throw new Exception('Explain not available');
        }
        switch ($connection->driverName) {
            case 'oci':
                $connection->createCommand($procedure)->execute();

                return $connection->createCommand('SELECT * FROM table(dbms_xplan.display)')->queryAll();
            default:
                return $connection->createCommand($procedure)->queryAll();
        }
    }

    /**
     * Return connection list for query.
     *
     * @param string $query
     *
     * @return array connection list
     */
    public function getExplainConnections($query)
    {
        $connections = [];
        foreach ($this->data['connections'] as $name => $connection) {
            if (null !== $this->getExplainQuery($query, $connection['driver'])) {
                $connections[$name] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @param int $number
     *
     * @return string sql-query
     */
    public function messageByNum($number)
    {
        foreach ($this->calculateTimings() as $timing) {
            if (!$number--) {
                return $timing[1];
            }
        }

        return null;
    }
}
