<?php

declare(strict_types=1);

$destination = $argv[1] ?? throw new InvalidArgumentException('Expected plugin PHAR path');
$phar = new Phar($destination);
$phar->startBuffering();
$phar->setStub('<?php __HALT_COMPILER();');
$phar->addFromString('plugin.yml', "name: NativeLegacySmoke\nmain: ryzemp\\integration\\NativeLegacySmoke\nversion: 1.0.0\napi: [5.0.0]\n");
$phar->addFile(__DIR__ . '/legacy419/NativeLegacySmoke.php', 'src/ryzemp/integration/NativeLegacySmoke.php');
$phar->stopBuffering();
echo $destination . PHP_EOL;
