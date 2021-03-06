Beanstalk ドライバ
==================

このドライバは Beanstalk のキューによって動作します。

構成例:

```php
return [
    'bootstrap' => [
        'queue', // コンポーネントが自身のコンソール・コマンドを登録します
    ],
    'components' => [
        'queue' => [
            'class' => \Yiisoft\Yii\Queue\beanstalk\Queue::class,
            'host' => 'localhost',
            'port' => 11300,
            'tube' => 'queue',
        ],
    ],
];
```

コンソール
----------

キューに入れられたジョブの実行と管理のためにコンソール・コマンドが使用されます。

```sh
yii queue/listen [timeout]
```

`listen` コマンドは無限にキューを調べ続けるデーモンを起動します。キューに新しいタスクがあると、即座に取得され、実行されます。
`timeout` パラメータはキューを調べる間のスリープの秒数を指定するものです。
このコマンドを [supervisor](worker.md#supervisor) または [systemd](worker.md#systemd) によって適切にデーモン化するのが、
最も効率的な方法です。

```sh
yii queue/run
```

`run` コマンドは、キューが空になるまでループして、タスクを取得し、実行します。
[cron](worker.md#cron) に向いた方法です。

`run` および `listen` のコマンドは下記のオプションを持っています。

- `--verbose`, `-v`: 実行の状態をコンソールに表示します。
- `--isolate`: ジョブ実行の饒舌モード。有効な場合、各ジョブの実行結果が表示されます。
- `--color`: 饒舌モードでハイライトを有効にします。

```sh
yii queue/info
```

`info` コマンドはキューの状態について情報を表示します。

```sh
yii queue/remove [id]
```

`remove` コマンドはキューからジョブを削除します。
