<?php

declare(strict_types=1);

namespace Install;

use PDO;
use PDOException;
use RuntimeException;

final class DatabaseProbe
{
    /** @param array{host:string,port:int,name:string,user:string,password:string,create:bool} $config
     *  @return array{ok:bool,message:string,version:string,charset:string,collation:string,ngram:?string,pdo:?PDO}
     */
    public function probe(array $config): array
    {
        $host = trim($config['host']);
        $port = (int) $config['port'];
        $name = trim($config['name']);
        $user = $config['user'];
        $password = $config['password'];

        if ($host === '' || $port < 1 || $port > 65535) {
            return $this->fail('数据库主机或端口不合法。');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return $this->fail('数据库名只允许字母、数字和下划线。');
        }
        if ($user === '') {
            return $this->fail('数据库用户不能为空。');
        }
        if (in_array(strtolower($user), ['root', 'admin'], true)) {
            return $this->fail('不要使用 root/admin 账号安装；请创建只拥有该库权限的专用账号。');
        }

        try {
            $server = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );

            if ($config['create']) {
                $server->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
                    $name,
                ));
            }

            $db = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );

            $versionStatement = $db->query('SELECT VERSION()');
            $version = $versionStatement === false ? '' : (string) $versionStatement->fetchColumn();
            if (version_compare($version, '9.7.4', '<')) {
                return $this->fail(sprintf('MySQL %s 低于目标版本 9.7.4，请先升级。', $version));
            }

            $charsetStatement = $db->query('SELECT @@character_set_database');
            $charset = $charsetStatement === false ? '' : (string) $charsetStatement->fetchColumn();
            $collationStatement = $db->query('SELECT @@collation_database');
            $collation = $collationStatement === false ? '' : (string) $collationStatement->fetchColumn();

            $ngram = null;
            try {
                $ngramStatement = $db->query('SELECT @@ngram_token_size');
                $ngram = $ngramStatement === false ? null : (string) $ngramStatement->fetchColumn();
            } catch (PDOException) {
                // 该变量不是所有发行版都开放，安装不因此阻断。
            }

            return [
                'ok' => true,
                'message' => sprintf('MySQL %s，字符集 %s，排序规则 %s。', $version, $charset, $collation),
                'version' => $version,
                'charset' => $charset,
                'collation' => $collation,
                'ngram' => $ngram,
                'pdo' => $db,
            ];
        } catch (PDOException $exception) {
            return $this->fail($this->friendlyError($exception));
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    private function friendlyError(PDOException $exception): string
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return match ($driverCode) {
            1045 => '数据库账号或密码错误。',
            1049 => '数据库不存在；可勾选“允许创建数据库”，或先手动创建。',
            1044 => '数据库账号没有访问该库的权限。',
            2002 => '无法连接数据库主机，请检查地址、端口和防火墙。',
            default => '数据库连接失败，请检查账号、库名和权限。详细原因已写入安装日志。',
        };
    }

    /** @return array{ok:false,message:string,version:string,charset:string,collation:string,ngram:null,pdo:null} */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'version' => '',
            'charset' => '',
            'collation' => '',
            'ngram' => null,
            'pdo' => null,
        ];
    }
}
