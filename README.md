# RedisUtil - Redisの様々な型のデータの検索および保存をそれぞれ単一のメソッドで実行するクラス

Redisは非常に便利なKVSですが、KEYに対するVALUEに様々な型があり、それぞれの型に対応したメソッドを使用しなければならないという仕組みになっています。

このクラスライブラリは、Redisの型に合ったメソッドを意識せずに、検索・保存をそれぞれ単一のメソッドで処理できるようにするものです。

## インストール

### Redisのインストール

* Linux版は各ディストリビューションのパッケージシステムからインストールするか、[公式ページ](http://redis.io/)からソースをダウンロードし、コンパイルしてインストールしてください。
* Windows版は<https://github.com/dmajkic/redis/downloads>からダウンロードできます。

### phpredisのインストール

Redisへの接続には[phpredis](https://github.com/nicolasff/phpredis/)が必要なので、別途インストールしてください。

* Linux版は上記リンク先のインストール方法に従い、インストールしてください。
* Windows版は上記リンク先のDownloadsタブからコンパイル済みのDLLをダウンロードできます。

拡張モジュールのインストールの仕方については割愛します。

### RedisUtilのインストール

RedisUtil.phpをPHPのinclude\_pathの通ったディレクトリに置いてください。

## 使用方法

以下、簡単なサンプルです。

    <?php
    require_once('RedisUtil.php');

    // redisUtilインスタンス作成
    $redisUtil = new RedisUtil(array(
        'host' => 'localhost',
        'port' => 6379,
        'db' => 0,
        'persistent' => false,
    ));

    // KEY: key_hoge_str に VALUE: value_fuga をstring型で保存
    $redisUtil->save('key_hoge_str', 'value_fuga');
    // KEY: key_hoge_hash に VALUE: value_fuga をhash型で保存
    $redisUtil->save('key_hoge_hash', array('fuga1' => 'あいうえお', 'fuga2' => 'abcde'), 'hash');

    // KEY: key_hoge_str に保存されているVALUEを取得
    $value = $redisUtil->find('key_hoge_str');
    // KEY: key_hoge_* にマッチするKEYのVALUEを全て取得
    foreach ($redisUtil->find('key_hoge_*') as $key => $value) {
        // ...
    }

## メソッド

### new (コンストラクタ)

    RedisUtil __construct( [array $config = array()])

RedisUtilのインスタンスを作成します。引数には接続設定を記述した連想配列を指定します。

引数を空にした場合はデフォルトの設定で初期化されます。接続設定のオプションは以下の通りです。

#### パラメータ

* host `string`
    * default: `"localhost"`
    * 接続するRedisサーバーが稼動しているホスト名を指定します。
* port `int`
    * default: `6379`
    * 接続するRedisサーバーが稼動しているポート番号を指定します。
* socket `string`
    * default: `null`
    * unixドメインソケットで接続する場合にパスを指定します。socketを指定した場合はhost、portオプションは無視されます。
* db `int`
    * default: `0`
    * Redisは0～15までの16個のDBを持っています。このオプションで接続先のDBを指定します。
* persistent `boolean`
    * default: `false`
    * 接続を永続化する場合は`true`を指定します。`true`にした場合、明示的にRedisの接続を閉じる必要があります(`$redisUtil->connection->close()`)。

#### サンプル

    // デフォルトの設定で接続
    $redis = new RedisUtil();

    // 設定を指定して接続
    $redis = new RedisUtil(array(
        'host' => 'example.com',
        'port' => 16379,
        'db' => 15,
        'persistent' => true
    ));

    // 基本はデフォルトでDBだけ変更
    $redis = new RedisUtil(array(
        'db' => 1
    ));

    // unixドメインソケットで接続
    $redis = new RedisUtil(array(
        'socket' => '/tmp/redis.sock'
    ));

### save

     mixed save( string $key, mixed $value, [string $type = null], [int $expire = null])

`$key`と`$value`のペアをRedisに保存します。`$value`は文字列、配列、連想配列などRedisの型によって変わります。

あいまいさ回避のため、Redisに保存する時の型を `$type`で指定する必要があります。ただしstring型の場合は省略できます。

パラメータの不備があると`RedisUtilException`例外が発生します。エラーの詳細は例外を補足したブロックで`RedisUtilException`のエラー情報を参照してください。

#### サンプル

    try {
        // keyにnullを指定
        $redisUtil->save(null, 'value_fuga');
    } catch (Exception $e) {
        echo $e->getMessage(); // -> 'invalid key type.'
        echo $e->getCode(); // -> RedisUtilException::ERR_INVALID_KEY
        // 以下のメソッドはRedisuUtilExceptionで独自に実装しているものです
        echo $e->getData(); // -> null
    }

戻り値は、基本的にはphpredisの各型の保存メソッドのものを使用するので、`$type`で指定した型によって変わります(詳細は後述)。

#### パラメータ

* key `string`

    1文字以上の文字列、もしくは数値を指定します。それ以外の型や空文字を指定した場合は例外が発生します。

* value `mixed`

    保存する際の`$type`の指定によって変わります。詳細は後述の各型の保存時の仕様の項で説明します。

* type `string`

    `"string"`、`"set"`、`"list"`、`"zset"`、`"hash"`のいずれかを指定します(大文字小文字どちらでも可)。

    `$type`を指定しなかったり、`null`を指定した場合はstring型として処理します。

    それ以外の指定をした場合や、`$type`で指定した型と`$value`の内容に不整合がある場合には例外が発生します。

    また、すでにRedisに`$key`が存在している場合、その`$key`の型と異なる型で保存しようとしても例外が発生します。

* expire `int`

    `$key`の有効期間を秒で指定します。Redisに保存されてから`$expire`秒後に、その`$key`は自動的にRedisから削除されます。

    `$expire`に`null`や0以下を指定した場合、もしくは`$expire`を指定しなかった場合は有効期間は設定されません(明示的に削除メソッドを実行するまで保持されます)。

    なお、`$expire`に整数以外を指定した場合は例外が発生します(`"60"`などの数値文字列は有効です)。

#### 各型の保存時の仕様

##### string型

* `$value`に文字列、もしくは数値を指定できます。数値を指定した場合は文字列として処理します。
* 戻り値は、成功:`true`、失敗:`false`が返ります。

###### サンプル

    $redisUtil->save('key_hoge', 'value_fuga'); // -> true

##### set型

* `$value`には文字列、数値、もしくはそれらをまとめた配列を指定することができます。文字列(数値)の場合は1つの値を保存、配列の場合は配列内の値をまとめて保存できます。
* 値に数値を指定した場合は文字列として処理されます。
* 指定した値、もしくは配列内の値に文字列や数値以外(`null`や`true`など)が入っていた場合は例外が発生します。
* 戻り値は、1値毎に`true`もしくは`false`が入った配列が返ります。保存した値が1つでも配列で返ります。
    * `true`: 新規に値を`$key`に追加した。
    * `false`: すでに`$key`に同じ値が存在していたので何もしなかった。

###### サンプル

    $redisUtil->save('key_hoge', 'value_fuga', 'set'); // -> array(true)
    $redisUtil->save('key_hoge', array('value_fuga', 'value_fugafuga'), 'set'); // -> array(false, true)

##### list型

* `$value`の仕様はset型と同様です。
* 戻り値は、1値毎に`$key`に保存されている値の個数、もしくは`false`(保存に失敗)が入った配列が返ります。保存した値が1つでも配列で返ります。

###### サンプル

    $redisUtil->save('key_hoge', 'value_fuga', 'list'); // -> array(1)
    $redisUtil->save('key_hoge', array('value_fugafuga', 'value_fugafugafuga'), 'list'); // -> array(2, 3)

##### zset型

* `$value`には{インデックス=>スコア}の連想配列のみ指定可能です。なお、スコアは数値、もしくは小数である必要があります。
* インデックスに文字列や数値以外(`null`や`true`など)が入っていた場合は例外が発生します。
* 戻り値は、1値毎に`1`もしくは`0`が入った配列が返ります。保存した値が1つでも配列で返ります。
    * `1`: 新規に{インデックス=>スコア}のペアを`$key`に追加した。
    * `0`: すでに`$key`に同じインデックスが存在していたので、該当するスコアを上書きした。

###### サンプル

    $redisUtil->save('key_hoge', array('fuga1' => 3), 'zset'); // -> array(1)
    $redisUtil->save('key_hoge', array('fuga1' => 5, 'fuga2' => 10), 'zset'); // -> array(0, 1)

##### hash型

* `$value`には{インデックス=>値}の連想配列のみ指定可能です。
* インデックスに文字列や数値以外(`null`や`true`など)が入っていた場合は例外が発生します。
* 戻り値は、1値毎に`1`か`0`、もしくは`false`(保存に失敗)が入った配列が返ります。保存した値が1つでも配列で返ります。
    * `1`: 新規に{インデックス=>値}のペアを`$key`に追加した。
    * `0`: すでに`$key`に同じインデックスが存在していたので、該当する値を上書きした。

###### サンプル

    $redisUtil->save('key_hoge', array('fuga1' => 'aaa'), 'hash'); // -> array(1)
    $redisUtil->save('key_hoge', array('fuga1' => 'bbb', 'fuga2' => 'ccc'), 'hash'); // -> array(0, 1)

### find

    mixed find( mixed $keyPattern, [array $option = array()])

`$keyPattern`で指定したKEYに該当するVALUEを返します。なお、`$keyPattern`の指定の仕方によって戻り値の型が異なります。

* `$keyPattern`の指定が`hoge*`や`ho?e`などのパターンマッチングの場合は、複数の該当がある可能性があるので、KEY => VALUEのペアを返すイテレータが戻り値となります(結果的に該当が1ペアの場合でもイテレータで返されます)。取得した戻り値を`foreach`などで回して処理してください。

        // パターンでKEYを指定
        $matches = $redisUtil->find('key_hog*');
        foreach ($matches as $key => $value) {
            print_r($key);   // -> 'key_hoge'
            print_r($value); // -> 'value_fuga'
        }

* `$keyPattern`がパターンマッチングではなく、単一のKEYとして指定した場合は、該当が複数になることはないので、`$keyPattern`に該当するVALUE自体が戻り値となります。

        // 単一KEYで指定
        $match = $redisUtil->find('key_hoge_list');
        print_r($match); // -> array('value_fuga001', 'value_fuga002', ... 'value_fuga100');

* `$keyPattern`に配列を指定することにより、複数のパターンでまとめて検索が可能です。この場合も該当が複数ある可能性があるので、戻り値はKEY => VALUEのペアを返すイテレータとなります。

        // KEYに配列を指定してまとめて検索する
        $match = $redisUtil->find(array('key_hoge', 'key_fug*'), array('limit' => 10));
        print_r($match); // -> array('value_hoge', 'value_fuga')

* `$keyPattern`に文字列、配列以外を指定したり、配列内のKEYが文字列以外の場合は例外が発生します(数値は数値文字列として扱います)。
* `$keyPattern`に該当するKEYがRedisに存在しなければ`null`が返ります。
* 取得した値はzsetのスコアはdouble型、それ以外は全て文字列で返ります。

#### パターンの指定例

<http://redis.shibu.jp/commandreference/alldata.html>より抜粋

>        h?llo will match hello hallo hhllo
>        h*llo will match hllo heeeello
>        h[ae]llo will match hello and hallo, but not hillo
>        Use \ to escape special chars if you want to match them verbatim.

上記リンク先でも言及していますが、パターンでKEYを指定する場合、大量に該当がある場合はRedisのパフォーマンスに影響を与える可能性がありますのでご注意下さい。

#### $optionパラメータ

検索オプションとして、以下の指定が可能です。なお、`$option`は取得するデータの型がlist、もしくはzsetの場合にのみ適用され、それ以外の型では無視されます。

また、`$option`の各項目に不正な型を指定した場合は例外が発生します。

* limit `int`
    * default: `null`(=全件)
    * Redisではstring型以外は1つのKEYに該当する値が配列やハッシュで保持されます。その内のzsetとlistについてはこのオプションの指定により、先頭から、もしくは末尾から指定した件数のみ取得する指定が可能です。
    * set、hashについては、先頭や末尾といった基準がないのでこのオプションは適用されません。
* reverse `boolean`
    * default: `false`
    * KEYに該当する値の配列を降順で取得します。
    * listにおける昇順/降順は値の並び順、zsetにおける昇順/降順はスコアが基準となります。

#### サンプル

    // 単一KEYで指定、かつ降順で20件取得する(list型)
    $match = $redisUtil->find('key_hoge_list', array('limit' => 20, 'reverse' => true));
    print_r($match); // -> array('value_fuga500', 'value_fuga499', ... 'value_fuga481');

    // 単一KEYで指定、かつスコア昇順で10件取得する(zset型)
    $match = $redisUtil->find('key_hoge_zset', array('limit' => 10));
    print_r($match); // -> array('value_fuga1' => 1, 'value_fuga2' => 3, ... 'value_fuga10' => 15);

### delete

    int delete( mixed $keyPattern)

`$keyPattern`で指定したKEYをRedisから削除します。`$keyPattern`は、findと同様にパターンで指定可能です。戻り値は削除したKEYの数が返ります。

`$keyPattern`に配列を指定することにより、複数のパターンでまとめて削除が可能です。

`$keyPattern`に文字列、配列以外を指定したり、配列内のKEYが文字列以外の場合は例外が発生します(数値は数値文字列として扱います)。

    // パターンでKEYを削除
    $deleteCount = $redisUtil->delete('key_hog*');

    // 単一KEYで指定
    $deleteCount = $redisUtil->delete('key_hoge');

    // 配列でまとめて削除
    $deleteCount = $redisUtil->delete(array('key_hog*', 'key_hoge'));

## プロパティ

### connection

RedisUtilクラスで保持しているphpredis接続オブジェクトです(ReadOnly)。

RedisUtilで提供している機能ではなく、phpredisのメソッドを直接使用したい場合はこのプロパティから呼び出します。

#### サンプル

    $redisUtil->connection->setnx('key_hoge', 'value_fuga');

## 開発環境

Windows及びLinux(ubuntu、CentOS)の以下の環境で開発、動作確認を行っています。

* PHP-5.3

## TODO(今後実装したい機能)

なんとなく考えている程度のレベルなので実装はしないかもしれませんが、アイディアメモとして記録しておきます。

* findメソッドでVALUEの内容を正規表現で対象判定するfilterオプションを追加(zset、hashの扱いを検討中)。

        // KEY: hoge*のパターンに該当して、かつVALUEがfilterの正規表現にマッチしたら抽出対象となる、みたいな。
        $redisUtil->find('hoge*', array('filter' => '/^value_(fuga|hogege)[0-9]+$/i'));

* updateメソッド追加(KEYとzsetやhashのインデックスをキーに値を更新する)。

        // KEY: hoge*のパターンに該当するzsetのcountが10以上ならlevelを2にする、みたいな。
        $redisUtil->update('hoge*', array('count' => '> 10'), array('level' => 2), 'zset');

* saveメソッドでzsetのzIncrByに対応(updateメソッドで実装した方が良いかも)。

        // KEY: hoge1、hoge2のfugaのスコアを+1する、みたいな。
        $redisUtil->save(array('hoge1', 'hoge2'), array('fuga' => '+1'), 'zset');

* listの個数を保ったまま追加する(はみ出た分は自動的に削除)みたいなメソッドの実装。

        ('aaa', 'bbb', 'ccc') => ('bbb', 'ccc', 'ddd') // 3個を超えると先頭の'aaa'が消える、みたいな。

* CakePHPのモデルの`$hasOne`や`$hasMany`のようにRedis内で一定のルールに基づいたリレーションを取れるようにしたい。

## Changelog

### 0.1.0 (2012-08-04)

* 初版リリース

## ライセンス

[MIT license](http://www.opensource.org/licenses/mit-license)で配布します。

&copy; 2012 [ktty1220](mailto:ktty1220@gmail.com)

