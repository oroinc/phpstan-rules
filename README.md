# Unsafe DQL usage analysis

## Why DQL and SQL queries should be checked
Using DQL does not protect against injection vulnerabilities. The following APIs are designed to be SAFE from SQL injections:
 - For Doctrine\DBAL\Connection#insert($table, $values, $types), Doctrine\DBAL\Connection#update($table, $values, $where, $types) and Doctrine\DBAL\Connection#delete($table, $where, $types) only the array values of $values and $where. The table name and keys of $values and $where are NOT escaped.
 - Doctrine\DBAL\Query\QueryBuilder#setFirstResult($offset)
 - Doctrine\DBAL\Query\QueryBuilder#setMaxResults($limit)
 - Doctrine\DBAL\Platforms\AbstractPlatform#modifyLimitQuery($sql, $limit, $offset) for the $limit and $offset parameters.

Consider ALL other APIs to be not safe for user-input:

 - Query methods on the Connection
 - The QueryBuilder API
 - The Platforms and SchemaManager APIs to generate and execute DML/DDL SQL statements
 - Expressions constructed with help of various Expression Builders
 
 See full article at [Doctrine Security](http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/security.html)

## Static code analysis - Execution
Checking whole codebase requires a lot of time. To simplify this task sql-injection search tool was added. 
It is based on [PHPStan - PHP Static Analysis Tool](https://github.com/phpstan/phpstan)
and implemented as additional Rule

To check codebase for unsafe DQL and SQL usages do the following actions:
 - install dependencies `composer install`
 - run check with `./vendor/bin/phpstan analyze -c config.neon <path_to_code> --autoload-file=<path_to_autoload.php>`
 
To speedup analysis it's recommended to run it in parallel on per package basis. This may be achieved with the help of the `parallel` command:
```
cd my_application/package/test-security/tool/sql-injection/
rm -rf logs;
mkdir logs;
ls ../../../ \
| grep -v "\-demo" | grep -v "demo-" | grep -v "test-" | grep -v "german-" \
| parallel -j 4  "./vendor/bin/phpstan analyze -c config.neon `pwd`/../../../{} --autoload-file=`pwd`/../../../../application/commerce-crm-ee/app/autoload.php > logs/{}.log"
```
Note that commerce-crm-ee application should have `autoload.php` generated
In a minute analise results will be available. Each of them should be checked carefully, if needed unsafe variables should be sanitized or escaped to be safe.

## HOW TO fix found warnings
Unsafe variable and methods are any methods that may depend on external data. Even cached data may be unsafe in case when attacker has access to cache storage.
Any variable that came from outside or contains value returned by unsafe function is not safe and should be passed into queries with attention.
There are several options how to make variable safe

### ORM
ORM based queries may contain vulnerable inputs, to keep them clean follow next rules 
 - parameters *MUST* use named bind parameters. Passing data directly as right operand is prohibited. 
 The only possible exception is numbers, `\DateTime` and booleans
 - All identifiers MUST BE [a-zA-Z0-9_] compatible.

If there is a need to pass some variable directly into query use `QueryBuilderUtil` safe methods
 - *getField* - use it when field is constructed with sprintf or concatenation. Example `select($alias . '.' . $field)`, `select('alias.', $field)`, `select(sprintf('%s.%s', $alias, $field)` and so on
 - *sprintf* - should be used instead of sprintf in cases when it can not be replaced with getField. Example select('IDENTITY(%s.%s) as %s', $alias, $fieldName, $fieldAlias)
 - *checkIdentifier* - should be used to check identifiers (alphanumeric strings). Variable passed to checkIdentifier is considered as safe and will be allowed in further usages
 - *checkField* - very same to checkIdentifier with exception that it's designed to check strings in format "\w+.\w+" (alias.fieldName). Variable passed to checkField is considered as safe and will be allowed in further usages
 - *checkParameter* - very same to checkIdentifier with exception that it's designed to check strings in format ":\w+" (:parameterName). Variable passed to checkParameter is considered as safe and will be allowed in further usages
 - *getSortOrder* - return ASC or DESC if one of this values is passed, otherwise throws exception. Used to clear sort directions passed as parameters
    
 > NOTE!!! ->select(sprintf(%s as something', $fullName)) may be not quick fixed as $fullName may contain CONCAT(firstName, lastName) or any other statement. Such calls should be checked and marked safe
    
### DBAL
Use bind parameters or quote them with connection quote method. Identifiers should be either checked fore safety with QueryBuilderUtil 
or quoted with quoteIdentifier method of connection

## Static code analysis - Configuration
In case when variable, property or method considered as safe after detailed manual analysis it may be added to `trusted_data.neon`.
Such items will be marked as safe during further checks and will be skipped.

Available `trusted_data.neon` configuration sections are:
 - `variables` - whitelist of safe variables. Format `class.method.variable: true`
 - `properties` - whitelist of safe properties. Format `class.method.property: true`
 - `safe_methods` - whitelist of safe class methods. Format `class.method: true`
 - `safe_static_methods` - whitelist of safe class static methods. Format `class.method: true`
 - `check_methods_safety` - consider method safe if passed variables are safe. Format `class.method: true` when all passed variables should be checked
  or `class.method: [1]` when only certain variables require checks (their positions are listed in array)
 - `check_static_methods_safety` - consider static method safe if passed variables are safe. Format `class.method: true` when all passed variables should be checked
  or `class.method: [1]` when only certain variables require checks (their positions are listed in array)
 - `clear_methods` - variable is considered as safe if it is passed as argument into listed method. Format `class.method: true`
 - `clear_static_methods` - variable is considered as safe if it is passed as argument into listed static method. Format `class.method: true`
 - `check_methods` - contains a list of methods that are checked for safeness. If passed arguments are unsafe - security warning about such usage will be reported by analysis tool.
   Format `class.method: true` when all passed variables should be checked or `class.method: [1]` when only certain variables require checks (their positions are listed in array).
   Use `class.__all__: true` to check all class methods.
   For example there is SomeClass and we want to check all it's methods, except `method1` for which we want to enable 
   only first and third argument checks and for `method2` we want all arguments to be checked:
    ```yml
    check_methods:
        SomeClass:
            __all__: true
            method1: [0, 2]
            mrthod2: true
    ```

Prefer to mark methods as safe. If variable consists of several parts, better to add minimal unsafe part to whitelist rather that whole expression

## Example
```php
protected function addWhereToQueryBuilder(QueryBuilder $qb, string $suffix, int $index)
{
    $rootAlias = $qb->getRootAlias();
    $fieldName = $rootAlias . '.field' . $idx . $suffix;

    $qb->andWhere($qb->expr()->gt($fieldName, 10);
}
```

Such code will lead to security warning, as `$fieldName` variable was constructed with use of several parts, some of which are not safe.
The best solution to make this expression safe is to check `$suffix` with `QueryBuilderUtil::checkIdentifier($suffix)`
Other possible option is to add `$suffix` into `trusted_data.neon` whitelist if it's values are always passed as safe or checked in caller.
Worse solution is to mark `$fieldName` as safe because it's parts may be changed and, after adding new of unsafe part, it will be skipped, but may contain unchecked vulnerability.
