<?php
/**
 * Redisの様々な型のデータの検索および保存をそれぞれ単一のメソッドで実行するRedisUtilクラス
 *
 * - Redisへの接続には phpredis({@link https://github.com/nicolasff/phpredis/}) が必要なので別途インストールする。
 * - Windows版は同リンク先のdownloadからコンパイル済みDLLをダウンロード可能。
 * - PHP-5.3で動作確認済。
 *
 * @package RedisUtil
 * @version 0.1.0
 * @author ktty1220 <ktty1220@gmail.com>
 * @copyright Copyright (C) 2012 by ktty1220. All rights reserved.
 * @license http://www.opensource.org/licenses/mit-license.php MIT License.
 */

/**
 * RedisUtil用の例外クラス
 *
 * @package RedisUtilException class
 */
class RedisUtilException extends Exception {
    /**
     * @const エラーコード一覧
     */
    const ERR_INVALID_KEY = 1;
    const ERR_EMPTY_KEY = 2;
    const ERR_INVALID_EXPIRE = 3;
    const ERR_INVALID_TYPE = 4;
    const ERR_EMPTY_ARRAY = 5;
    const ERR_INVALID_ARRAY = 6;
    const ERR_INVALID_VALUE = 7;
    const ERR_EMPTY_VALUE = 8;
    const ERR_NON_NUMERIC_SCORE = 9;
    const ERR_VALUE_TYPE_MISSMATCH = 10;
    const ERR_EXISTING_KEY_MISSMATCH = 11;
    const ERR_NON_INT_LIMIT = 12;
    const ERR_NON_BOOL_REVERSE = 13;

    /**
     * @var array エラーメッセージ対応テーブル
     * @access protected
     */
    protected $errorMessageTable = array(
        self::ERR_INVALID_KEY => 'invalid key type.',
        self::ERR_EMPTY_KEY => 'key is empty.',
        self::ERR_INVALID_EXPIRE => 'invalid expire.',
        self::ERR_INVALID_TYPE => 'invalid type.',
        self::ERR_EMPTY_ARRAY => 'value is empty array.',
        self::ERR_INVALID_ARRAY => 'invalid array value.',
        self::ERR_INVALID_VALUE => 'invalid value type.',
        self::ERR_EMPTY_VALUE => 'value is empty.',
        self::ERR_NON_NUMERIC_SCORE => 'score is not numeric.',
        self::ERR_VALUE_TYPE_MISSMATCH => "value's type is %s, but type specified is %s.",
        self::ERR_EXISTING_KEY_MISSMATCH => "key is existing as %s, but type specified is %s.",
        self::ERR_NON_INT_LIMIT => 'option: limit is not int.',
        self::ERR_NON_BOOL_REVERSE => 'option: reverse is not bool.',
    );

    /**
     * @var string 例外の原因となったパラメータ
     */
    private $type;
    /**
     * @var string 例外の原因となったデータ
     */
    private $data;

    /**
     * コンストラクタ
     *
     * @param string $code エラーコード
     * @param mixed $data 例外の原因となったデータ
     * @param array $info エラーメッセージにvsprintf()で埋め込む値の配列
     */
    function __construct($code, $data = null, $info = null) {
        $msg = ($info) ? vsprintf($this->errorMessageTable[$code], $info) : $this->errorMessageTable[$code];
        parent::__construct($msg, $code);
        $this->data = $data;
    }

    /**
     * 例外の原因となったデータを取得
     * @return mixed データ
     */
    function getData() {
        return $this->data;
    }
}

/**
 * kEY => VALUE のペアを返すRedis用イテレータ
 *
 * @access private
 * @package RedisIterator class
 */
class RedisIterator implements Iterator {
    private $conn = null;           // Redis接続オブジェクト
    private $keys = array();         // 検索パターンに該当したKEY配列
    private $position = 0;           // 該当したKEY配列のイテレータ上の現在位置
    private $option = array();       // その他のオプション
    private $zRangeFunc = 'zRange';  // (zsetのみ)スコア取得関数(zRange: 昇順 / zRevRange: 降順)

    /**
     * 配列の[キー|インデックス]に該当する値を取得する([キー|インデックス]の存在チェック含む)
     *
     * @param array $array 検索対象の連想配列
     * @param string|int $index 検索する[キー|インデックス]
     * @return mixed [キー|インデックス]に該当する値([キー|インデックス]が存在しなければnull)
     */
    private function _array_get_value($array, $index) {
        if (is_array($array) && array_key_exists($index, $array)) {
            return $array[$index];
        }
        return null;
    }

    /**
     * 検索オプション'limit'の内容を取得してデータ件数と比較して微調整する
     * @param int $arrayLen 基準となるデータ件数
     * @return int 調整済みのlimit
     */
    private function _getLimit($arrayLen) {
        $limit = 0;
        $optLimit = $this->_array_get_value($this->option, 'limit');
        if (!is_null($optLimit)) $limit = $optLimit;
        if ($limit > 0 && $limit > $arrayLen) {
            $limit = $arrayLen;
        }
        return $limit;
    }

    /**
     * コンストラクタ
     *
     * @param object &$conn Redis接続オブジェクトのリファレンス
     * @param string $keyPattern 検索KEYパターン
     * @param array $opt 検索オプション(limit, reverse) ※ [list|zset]のみ
     * @see RedisUtil::find()
     */
    public function __construct(&$conn, $keyPattern, $opt = array()) {
        $this->conn = $conn;

        // 該当KEYを確保
        if (is_array($keyPattern)) {
            foreach ($keyPattern as $k) {
                if (!is_scalar($k) || is_bool($k)) {
                    throw new RedisUtilException(RedisUtilException::ERR_INVALID_KEY, $k);
                }
                $this->keys = array_merge($this->keys, $this->conn->keys($k));
            }
            $this->keys = array_unique($this->keys);
            sort($this->keys);
        } else {
            if (!is_scalar($keyPattern) || is_bool($keyPattern)) {
                throw new RedisUtilException(RedisUtilException::ERR_INVALID_KEY, $keyPattern);
            }
            $this->keys = $this->conn->keys($keyPattern);
        }

        $this->option = array_merge($this->option, $opt);

        // オプションの型チェック
        $optReverse = $this->_array_get_value($this->option, 'reverse');
        if (!is_null($optReverse) && !is_bool($optReverse)) {
            throw new RedisUtilException(RedisUtilException::ERR_NON_BOOL_REVERSE, $this->option);
        }
        $optLimit = $this->_array_get_value($this->option, 'limit');
        if (!is_null($optLimit) && (!is_numeric($optLimit) || is_double($optLimit) || $optLimit < 0)) {
            throw new RedisUtilException(RedisUtilException::ERR_NON_INT_LIMIT, $this->option);
        }

        $this->position = 0;
        if ($optReverse === true) $this->zRangeFunc = 'zRevRange';
    }

    /**
     * イテレータ上の現在位置を初期化
     */
    function rewind() {
        $this->position = 0;
    }

    /**
     * イテレータ上の現在位置のVALUEを返す
     *
     * @return mixed Redisのデータ型による(string型はstring、不明はnull、それ以外はarray)
     */
    function current() {
        if (count($this->keys) === 0) {
            return null;
        }
        $currentKey = $this->keys[$this->position];  // 現在位置のKEY
        $conn = & $this->conn;                        // 記述短縮化目的用変数
        $type = $conn->type($currentKey);             // 現在位置のKEYに該当するVALUE
        $ret = null;                                 // 戻り値

        switch($type) {
        case Redis::REDIS_STRING:
            // 取得値をそのまま返す
            $ret = $conn->get($currentKey);
            break;
        case Redis::REDIS_SET:
            // 取得値をそのまま返す
            $ret = $conn->sInter($currentKey);
            break;
        case Redis::REDIS_LIST:
            $ret = array();
            $listLen = $conn->lLen($currentKey);
            $limit = $this->_getLimit($listLen);

            // 取得開始位置、取得終了位置の設定
            if ($this->_array_get_value($this->option, 'reverse')) {
                $start = ($limit === 0) ? 0 : $listLen - $limit;
                $end = -1;
            } else {
                $start = 0;
                $end = ($limit === 0) ? -1 : $limit - 1;
            }

            $ret = $conn->lRange($currentKey, $start, $end);

            // reverse指定なら取得した値の順番も逆順にする
            if ($this->_array_get_value($this->option, 'reverse')) {
                $ret = array_reverse($ret);
            }
            break;
        case Redis::REDIS_ZSET:
            $ret = array();
            $func = $this->zRangeFunc;
            $zLen = $conn->zCard($currentKey) ;
            $limit = $this->_getLimit($zLen);
            $end = (($limit === 0) ? 0 : $limit) - 1;

            // 取得値とスコアのペアを連想配列に追加していく
            foreach ($conn->$func($currentKey, 0, $end) as $z) {
                $ret += array($z => $conn->zScore($currentKey, $z));
            }
            break;
        case Redis::REDIS_HASH:
            $ret = array();
            // 取得値とスコアのペアを連想配列に追加していく
            foreach ($conn->hKeys($currentKey) as $h) {
                $ret += array($h => $conn->hGet($currentKey, $h));
            }
            break;
        case Redis::REDIS_NOT_FOUND:
        default:
            $ret = 'other';
            break;
        }
        return $ret;
    }

    /**
     * イテレータ上の現在位置のKEYを返す
     *
     * @return string KEY
     */
    function key() {
        return $this->_array_get_value($this->keys, $this->position);
    }

    /**
     * イテレータ上の現在位置を次へ移動
     */
    function next() {
        ++$this->position;
    }

    /**
     * イテレータ上の現在位置の有効判定
     *
     * @return boolean 現在位置の有効可否
     */
    function valid() {
        return (count($this->keys) > $this->position);
    }
}

/**
 * RedisUtilクラス本体
 *
 * @package RedisUtil class
 *
 * @property-read object $connection phpredis接続オブジェクト(readonly)
 */
class RedisUtil {
    /**
     * @var array Redis接続の初期値(host, port, db)
     */
    protected $settings = array(
        'socket' => null,       // unixドメインのソケットパス
        'host' => '127.0.0.1',  // ホスト
        'port' => 6379,         // ポート
        'db' => 0,              // DB指定(0～15)
        'persistent' => false,  // 接続を維持する
    );

    /**
     * @var object phpredis接続オブジェクト
     */
    private $connection;

    /**
     * @var array Redis型チェック用連想配列
     */
    private $typeCheck = array(
        'string' => Redis::REDIS_STRING,
        'set' => Redis::REDIS_SET,
        'list' => Redis::REDIS_LIST,
        'zset' => Redis::REDIS_ZSET,
        'hash' => Redis::REDIS_HASH,
    );

    /**
     * コンストラクタ
     *
     * - $configにsocketとhost,portの両方が指定された場合はsocketを優先する。
     *
     * <code>
     * // デフォルトの設定で接続
     * $redis = new RedisUtil();
     *
     * // 設定を指定して接続
     * $redis = new RedisUtil(array(
     *     'host' => 'example.com',
     *     'port' => 16379,
     *     'db' => 15,
     *     'persistent' => true
     * ));
     * </code>
     *
     * @param array $config Redis接続情報(値は$settings参照)
     * @see $settings
     */
    public function __construct($config = array()) {
        // デフォルトの接続情報を上書き
        $this->settings = array_merge($this->settings, $config);

        // 接続メソッド
        $connectMethod = ($this->settings['persistent']) ? 'pconnect' : 'connect';

        // Redis接続
        $this->connection = new Redis();
        if (!is_null($this->settings['socket'])) {
            $this->connection->$connectMethod($this->settings['socket']);
        } else {
            $this->connection->$connectMethod($this->settings['host'], $this->settings['port']);
        }

        // オプション設定(Redis::SERIALIZER_PHPにするとzaddやzscoreの処理がおかしくなる)
        #$this-connection->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        $this->connection->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        // DB指定
        $this->connection->select($this->settings['db']);
    }

    /**
     * RedisUtilクラスで保持しているのprivate変数をReadOnlyで取得する
     *
     * @access private
     */
    function __get($property) {
        return $this->$property;
    }

    /**
     * 指定したKEYパターンに該当するKEY=>VALUEのペア、もしくはKEYに該当するVALUEを返す
     *
     * $keyPattern
     * - $keyPatternに配列を指定すれば、配列内のKEYパターンをまとめて検索可能。
     * - $keyPatternに文字列、配列以外を指定したり、配列内のKEYが文字列以外の場合はRedisUtilException例外が発生する。
     * - パターンマッチングの指定方法は、{@link http://redis.shibu.jp/commandreference/alldata.html}を参照。
     * return
     * - $keyPatternの指定がパターンマッチングや配列など、該当するKEYが複数になる可能性がある指定の場合は、KEY=>VALUEのペアを返すイテレータが、単一KEYの指定の場合は該当したVALUEが返される。
     * - $keyPatternが存在しなければnullが返される。
     * - VALUEの値はzsetのスコアはdouble型、それ以外は全て文字列で返る。
     * $option
     * - $optionは取得するデータの型が[list|zset]の場合にのみ適用され、それ以外の型では無視される。
     * - $option['reverse']で、データの取得順(true: 降順, false(default): 昇順)を指定する。
     * - zsetにおける昇順/降順はスコアが基準。
     * - $option['limit']で、最大取得件数の指定(マッチしたKEYの件数の最大数ではない)を指定する。
     * - $optionの各項目に不正な型を指定した場合は例外が発生する。
     *
     * <code>
     * // パターンでKEYを指定
     * $matches = $redisUtil->find('key_hog*');
     * foreach ($matches as $key => $value) {
     *     print_r($key);   // -> 'key_hoge'
     *     print_r($value); // -> 'value_fuga'
     * }
     *
     * // 単一KEYで指定、かつ[list|zset]の場合は降順で20件取得する
     * $match = $redisUtil->find('key_hoge_list', array('limit' => 20, 'reverse' => true));
     * print_r($match); // -> array('value_fuga500', 'value_fuga499', ... 'value_fuga481');
     *
     * // KEYに配列を指定してまとめて検索する
     * $match = $redisUtil->find(array('key_hoge', 'key_fug*'), array('limit' => 10));
     * print_r($match); // -> array('value_hoge', 'value_fuga')
     * </code>
     *
     * @param mixed $keyPattern 検索KEYパターン(配列でまとめて指定可能)
     * @param array $option 検索オプション(limit, reverse) ※ [list|zset]のみ
     * @return mixed KEY=>VALUEのペアを返すイテレータ、もしくはKEYに該当するVALUE
     */
    public function find($keyPattern, $option = null) {
        $option or $option = array();
        $it = new RedisIterator($this->connection, $keyPattern, $option);
        if (!$it->key()) return null;

        // KEYをパターンや配列で指定している場合はイテレータを返す
        if (is_array($keyPattern) || preg_match('/(\*|\?|\[.*\])/', $keyPattern)) {
            return $it;
        }

        // 単一KEYの指定の場合は最初のペアを取得してVALUE部分を返す
        $it->rewind();
        return $it->current();
    }

    /**
     * 指定したKEYとVALUEのペアをRedisに保存する
     *
     * - [list|set]型の$valueはarray型の配列に入れることで複数まとめて指定できる。
     * - [zset|hash]型の$valueは連想配列で指定する。
     * - Redis保存処理の前に問題があった場合(パラメータ不備など)はRedisUtilException例外が発生する。
     * 例外の発生する条件
     * - $keyに文字列、数値以外を指定(空文字含む)
     * - [set|list]型で$value配列内に文字列、数値外が存在(nullなど)
     * - $value配列内のインデックスに文字列や数値以外が存在
     * - $typeに[string|set|list|zset|hash]以外を指定(nullはOK)
     * - $expireに整数以外を指定
     * - zset型で保存しようとする連想配列のスコアが数値でない
     * - $typeの指定と$valueの内容に不整合がある
     * - すでに$keyがRedisに存在していて、Redisに保存されている型と$typeが異なる
     * 詳細はtest.phpを参照
     *
     * <code>
     * // string型で保存(set)
     * $redisUtil->save('key_hoge', 'value_fuga');
     *
     * // set型で1つの値を追加(sadd)、かつこのKEYが1時間後に自動で消去されるようにする
     * $redisUtil->save('key_hoge', 'value_fuga1', 'set', 3600);
     *
     * // set型で複数の値を追加(sadd)
     * $redisUtil->save('key_hoge', array('value_fuga1', 'value_fuga2'), 'set');
     *
     * // list型でまとめて追加(rpush)
     * $redisUtil->save('key_hoge', array('value_fuga1', 'value_fuga2', 'value_fuga3'), 'list');
     *
     * // hash型で追加(hset)
     * $redisUtil->save('key_hoge', array('fuga1' => 'aaa', 'fuga2' => 'bbb'), 'hash');
     *
     * // zset型で追加(zadd) ※ member=>score(int)のペアの連想配列
     * $ret = $redisUtil->save('key_hoge', array('fuga1' => 1, 'fuga2' => 5), 'zset');
     *
     * try {
     *     // keyにnullを指定
     *     $redisUtil->save(null, 'value_fuga');
     * } catch (Exception $e) {
     *     echo $e->getMessage(); // -> 'invalid key type.'
     *     echo $e->getCode(); // -> RedisUtilException::ERR_INVALID_KEY
     *     echo $e->getData(); // -> null
     * }
     * </code>
     *
     * @param string $key 保存するKEY
     * @param mixed $value $keyに紐づくデータ
     * @param string $type データの型を指定する([string|set|list|zset|hash]) ※ string型は省略可能
     * @param int $expire $keyの有効期限を指定する(秒) ※ nullや0以下は無効
     * @return mixed Redisオブジェクトの保存処理の戻り値(stringはboolean、それ以外はarray)
     */
    public function save($key, $value, $type = null, $expire = null) {
        if (!is_scalar($key) || is_bool($key)) {
            throw new RedisUtilException(RedisUtilException::ERR_INVALID_KEY, $key);
        }
        if (!strlen((string) $key)) {
            throw new RedisUtilException(RedisUtilException::ERR_EMPTY_KEY, $key);
        }
        $expire or $expire = 0;
        if (!is_numeric($expire) || is_double($expire) || $expire < 0) {
            throw new RedisUtilException(RedisUtilException::ERR_INVALID_EXPIRE, $expire);
        }

        $type or $type = 'string';
        if (!is_scalar($type) || is_bool($type)) {
            throw new RedisUtilException(RedisUtilException::ERR_INVALID_TYPE, $type);
        }
        $type = strtolower($type);

        // $valueの型を取得
        $valueType = 'unknown';
        if (is_array($value)) {
            if (count($value) === 0) {
                throw new RedisUtilException(RedisUtilException::ERR_EMPTY_ARRAY, $value);
            }
            $valueType = 'array';
            // arrayが配列か連想配列か判定(KEYが0からの連番になっているかどうか)
            $i = 0;
            foreach($value as $k => $v) {
                if (!is_scalar($k) || is_bool($k) || !strlen((string) $k) || !is_scalar($v) || is_bool($v)) {
                    throw new RedisUtilException(RedisUtilException::ERR_INVALID_ARRAY, $value);
                } elseif ($k !== $i++) {
                    $valueType = 'hash';
                }
            }
        } elseif (!is_scalar($value) || is_bool($value)) {
            throw new RedisUtilException(RedisUtilException::ERR_INVALID_VALUE, $value);
        } else {
            if (strlen((string) $value) === 0) {
                throw new RedisUtilException(RedisUtilException::ERR_EMPTY_VALUE, $value);
            }
            // array以外は文字列とみなす
            $valueType = 'string';
        }

        // $typeに対して$valueの型が適切かチェック
        $typeError = false;
        switch($valueType) {
        case 'string':
            if (!preg_match('/^(string|set|list)$/', $type)) {
                $typeError = true;
            } else {
                // set|list指定で$valueが配列でない場合は配列化する
                if ($type !== 'string') $value = array($value);
            }
            break;
        case 'array':
            if (!preg_match('/^(set|list)$/', $type)) $typeError = true;
            break;
        case 'hash':
            if (!preg_match('/^(zset|hash)$/', $type)) $typeError = true;
            // zsetは要素のスコアが数値であるかチェック
            if ($type === 'zset') {
                foreach ($value as $k => $v) {
                    if (!is_numeric($v)) {
                        throw new RedisUtilException(RedisUtilException::ERR_NON_NUMERIC_SCORE, $value);
                    }
                }
            }
            break;
        default:
            $typeError = true;
        }

        if ($typeError) {
            throw new RedisUtilException(RedisUtilException::ERR_VALUE_TYPE_MISSMATCH, $value, array($valueType, $type));
        }

        // $KEYがすでにRedisに保存されているか、保存されている場合$typeが同一かチェック
        if ($this->connection->exists($key)) {
            $keyType = $this->connection->type($key);
            if ($this->typeCheck[$type] !== $keyType) {
                throw new RedisUtilException(RedisUtilException::ERR_EXISTING_KEY_MISSMATCH, $value, array($valueType, $type));
            }
        }

        // Redisに保存
        $result = null;
        switch ($type) {
        case 'string':
            $result = $this->connection->set((string) $key, (string) $value);
            break;
        case 'set':
        case 'list':
            $func = ($type === 'set') ? 'sAdd' : 'rPush';
            $multi = $this->connection->multi();
            foreach ($value as $v) {
                $multi->$func((string) $key, (string) $v);
            }
            $result = $multi->exec();
            // Linuxでsaddの戻り値がboolでなくintになっていたので統一
            if ($type === 'set') {
                $rcount = count($result);
                for ($i = 0; $i < $rcount; $i++) {
                    $result[$i] = (bool) $result[$i];
                }
            }
            break;
        case 'zset':
            $multi = $this->connection->multi();
            foreach ($value as $k => $v) {
                $multi->zAdd((string) $key, $v, (string) $k);
            }
            $result = $multi->exec();
            break;
        case 'hash':
            $multi = $this->connection->multi();
            foreach ($value as $k => $v) {
                $multi->hSet((string) $key, (string) $k, (string) $v);
            }
            $result = $multi->exec();
            break;
        }

        if (!is_null($result) && $expire > 0) {
            $this->connection->expire((string) $key, $expire);
        }

        return $result;
    }

    /**
     * 指定したKEYパターンに該当するKEYをRedisから削除する
     *
     * - KEYはパターンで指定可能。
     * - KEYに配列を指定すれば、配列内のKEYパターンをまとめて検索して該当KEYを削除可能。
     * - KEYに文字列、配列以外を指定したり、配列内のKEYが文字列以外の場合はRedisUtilException例外が発生する。
     *
     * <code>
     * // パターンでKEYを削除
     * $deleteCount = $redisUtil->delete('key_hog*');
     *
     * // 単一KEYで削除
     * $deleteCount = $redisUtil->delete('key_hoge');
     *
     * // 配列でまとめて削除
     * $deleteCount = $redisUtil->delete(array('key_hog*', 'key_hoge'));
     * </code>
     *
     * @param mixed $keyPattern 削除KEYパターン(配列でまとめて指定可能)
     * @return int 削除したKEYの数
     */
    public function delete($keyPattern) {
        $keys = array();
        if (is_array($keyPattern)) {
            foreach ($keyPattern as $k) {
                if (!is_scalar($k) || is_bool($k)) {
                    throw new RedisUtilException(RedisUtilException::ERR_INVALID_KEY, $keyPattern);
                }
                $keys = array_merge($keys, $this->connection->keys($k));
            }
            $keys = array_unique($keys);
            sort($keys);
        } else {
            if (!is_scalar($keyPattern) || is_bool($keyPattern)) {
                throw new RedisUtilException(RedisUtilException::ERR_INVALID_KEY, $keyPattern);
            }
            $keys = $this->connection->keys($keyPattern);
        }
        return $this->connection->del($keys);
    }
}
