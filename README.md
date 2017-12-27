# Unsafe DQL usage analysis

Using DQL does not protect against injection vulnerabilities. The following APIs are designed to be SAFE from SQL injections:
 - For Doctrine\DBAL\Connection#insert($table, $values, $types), Doctrine\DBAL\Connection#update($table, $values, $where, $types) and Doctrine\DBAL\Connection#delete($table, $where, $types) only the array values of $values and $where. The table name and keys of $values and $where are NOT escaped.
 - Doctrine\DBAL\Query\QueryBuilder#setFirstResult($offset)
 - Doctrine\DBAL\Query\QueryBuilder#setMaxResults($limit)
 - Doctrine\DBAL\Platforms\AbstractPlatform#modifyLimitQuery($sql, $limit, $offset) for the $limit and $offset parameters.

Consider ALL other APIs to be not safe for user-input:

 - Query methods on the Connection
 - The QueryBuilder API
 - The Platforms and SchemaManager APIs to generate and execute DML/DDL SQL statements
 
 See full article at [Doctrine Security](http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/security.html)

Checking whole codebase requires a lot of time. To simplify this task sql-injection search was added. It is based on [PHPStan - PHP Static Analysis Tool](https://github.com/phpstan/phpstan)
and implemented as additional Rule

To check codebase for unsafe DQL usages do the following actions:
 - install dependencies `composer install`
 - run check with `./vendor/bin/phpstan analyze -c config.neon <path_to_code> --autoload-file=<path_to_autoload.php>`

In a minute analise results will be available. Each of them should be checked carefully, if needed unsafe variables should be santized or escaped to be safe.
If variable, property or method is safe it may be added to `trusted_data.neon` and will be skipped during further checks.
In case when all ore some methods of class should be checked this class with methods should be added to `check_methods` section.
Use `__all__: true` to notify checker that all methods of class should be checked. If only certain arguments of method requires check their positions whould be added
in array.

For example there is SomeClass and we want to check all it's methods, except `method1` for which we want to enable 
only first and third argument checks and for `method2` we want all arguments to be checked:
```yml
check_methods:
    SomeClass:
        __all__: true
        method1: [0, 2]
        mrthod2: true
```
