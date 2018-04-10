# QueryBuilderInjectionRule

This rule analyzes source code for unsafe use of SQL and DQL queries.

## Why DQL and SQL queries should be checked

Using DQL does not protect against injection vulnerabilities.

See the full article at [Doctrine Security](http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/security.html)
  
The following APIs are designed to be SAFE from SQL injections:
 - For Doctrine\DBAL\Connection#insert($table, $values, $types), Doctrine\DBAL\Connection#update($table, $values, $where, $types) and Doctrine\DBAL\Connection#delete($table, $where, $types) only the array values of $values and $where. The table name and keys of $values and $where are NOT escaped.
 - Doctrine\DBAL\Query\QueryBuilder#setFirstResult($offset)
 - Doctrine\DBAL\Query\QueryBuilder#setMaxResults($limit)
 - Doctrine\DBAL\Platforms\AbstractPlatform#modifyLimitQuery($sql, $limit, $offset) for the $limit and $offset parameters.

Consider ALL other APIs to be not safe for user-input:

 - Query methods on the Connection
 - The QueryBuilder API
 - The Platforms and SchemaManager APIs to generate and execute DML/DDL SQL statements
 - Expressions constructed with the help of various Expression Builders
 

## How to fix the issues

Unsafe variables are produced by any methods that may depend on external data. Even cached data may be unsafe if an attacker manages to pollute the cache storage.
Any variable that comes from the outside, or contains a value returned by an unsafe function, is also unsafe and should be passed into queries with caution.

You can make a variable safe in a number of ways:

### ORM

ORM-based queries may contain vulnerable inputs. To keep them clean, follow the next rules:

- Parameter identifier *MUST* be a named placeholder. Passing data directly as the right operand is prohibited. The only possible exceptions are numbers, `\DateTime` and booleans.
- All identifiers MUST BE [a-zA-Z0-9_] compatible.

If there is a need to pass a variable directly into the query, use `QueryBuilderUtil` safe methods
 - *getField* - use it when a field is constructed with sprintf or concatenation. For example, `select($alias . '.' . $field)`, `select('alias.', $field)`, `select(sprintf('%s.%s', $alias, $field)` , etc.
 - *sprintf* - should be used instead of sprintf when it cannot be replaced with getField. For example, `select('IDENTITY(%s.%s) as %s', $alias, $fieldName, $fieldAlias)`
 - *checkIdentifier* - should be used to check identifiers (alphanumeric strings). A variable passed to checkIdentifier is considered safe and is allowed for further use.
 - *checkField* - similar to checkIdentifier, but designed to check strings formatted as "\w+.\w+" (alias.fieldName). A variable passed to checkField is considered safe and is allowed for further use.
 - *checkParameter* -similar to checkIdentifier, but designed to check strings formatted as  ":\w+" (:parameterName). A variable passed to checkParameter is considered safe and is allowed for further use.
 - *getSortOrder* - returns ASC or DESC if one of these values is passed. Otherwise, an exception will be thrown. Use to clear sort directions passed as parameters.
    
 > NOTE!!! ->select(sprintf(%s as something', $fullName)) may be not easily fixable if $fullName contains CONCAT(firstName, lastName) or some other statement. Such calls should be checked and may be marked safe.
    
### DBAL

Use parameter binding or quote them with the connection quote method. Identifiers should be either checked for safety with QueryBuilderUtil or quoted with the quoteIdentifier method of connection.

### Common warnings and possible ways to fix them

 - Unsafe field is used as a part of query:

    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq($field, ':parameter'));
    ```

    Fix - use `QueryBuilderUtil::checkField` to check the field for safeness:

    ```php
    QueryBuilderUtil::checkField($field);
    $queryBuilder->andWhere($queryBuilder->expr()->eq($field, ':parameter'));
    ```

 - Using composite identifier:

    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq($alias . '.' . $field, ':parameter'));
    ```

    Or:

    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq(sprintf('%s.%s', $alias, $field), ':parameter'));
    ```

    Possible fix - use `QueryBuilderUtil::getField`
    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq(QueryBuilderUtil::getField($alias, $field), ':parameter'));
    ```
    
    Fix 2 - check each identifier separately with `QueryBuilderUtil::checkIdentifier`
    ```php
    QueryBuilderUtil::checkIdentifier($alias);
    QueryBuilderUtil::checkIdentifier($field);
    $queryBuilder->andWhere($queryBuilder->expr()->eq($alias . '.' . $field, ':parameter'));
    ```
    
    Fix 3 - use safe `QueryBuilderUtil::sprintf`, also applicable when replacing sprintf
    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq(QueryBuilderUtil::sprintf('%s.%s', $alias, $field), ':parameter'));
    ```
    
 - Using composite parameter name
    ```php
    $queryBuilder->andWhere($queryBuilder->expr()->eq('table.id', $paramer));
    ```
     
    Fix - use `QueryBuilderUtil::checkParameter`
     
    ```php
    QueryBuilderUtil::checkParameter($paramer);
    $queryBuilder->andWhere($queryBuilder->expr()->eq('table.id', $paramer));
    ```
 - Using sort order passed from outside
 
    ```php
    $queryBuilder->orderBy('table.field', $sortOrder);
    ```
    
    Fix - use `QueryBuilderUtil::getSortOrder`
    
    ```php
    $queryBuilder->orderBy('table.field', QueryBuilderUtil::getSortOrder($sortOrder));
    ```
    
 - Literal is passed to query
    
    ```php
    $queryBuilder->select(sprintf("'%s' as className", $className));
    ```
    
    Fix use `literal` expression
    
     ```php
     $queryBuilder->select(
       sprintf((string)$queryBuilder->expr()->literal($className) . ' as className')
     );
     ```

## Static code analysis - Configuration
Trusted Data is organized per bundle, each separate config file is placed in `Tests/trusted_data.neon` and loaded automatically.
Configuration tree in `Tests/trusted_data.neon` files is organized under `trusted_data` root.
Trusted data may be also configured via DI as `sql_injection_testing.trusted_data`.

If a variable, a property or a method are considered safe after a detailed manual analysis, they may be added to `Tests/trusted_data.neon`.
Such items will be marked as safe during further checks and skipped.

Available Trusted Data configuration sections are:
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
 - `check_methods` - contains a list of methods that are checked for safeness. If passed arguments are unsafe, a security warning about such usage is reported by the analysis tool.
   Format `class.method: true` when all passed variables should be checked or `class.method: [1]` when only certain variables require checks (their positions are listed in array).
   Use `class.__all__: true` to check all class methods.
   For example, there is SomeClass and we want to check all its methods, except for  `method1`. For `method1`, 
   we want to enable only the first and third argument checks, and for `method2` we want all arguments to be checked:
    ```yml
    check_methods:
        SomeClass:
            __all__: true
            method1: [0, 2]
            mrthod2: true
    ```

It is recommended to mark methods as safe. If a variable consists of several parts, it is better to add a minimal unsafe part to the whitelist, rather than the whole expression.

## Example
```php
protected function addWhereToQueryBuilder(QueryBuilder $qb, string $suffix, int $index)
{
    $rootAlias = $qb->getRootAlias();
    $fieldName = $rootAlias . '.field' . $idx . $suffix;

    $qb->andWhere($qb->expr()->gt($fieldName, 10);
}
```

Such code will lead to a security warning, as `$fieldName` variable was constructed using several parts, some of which are not safe.
The best solution to make this expression safe is to check `$suffix` with `QueryBuilderUtil::checkIdentifier($suffix)`
Another option is to add `$suffix` into the `trusted_data.neon` whitelist if its values are always passed as safe or checked in the caller.
The worst solution would be to mark `$fieldName` as safe because its parts may be changed and, after adding a new or an unsafe part, it will be skipped, although it may contain an unchecked vulnerability.
