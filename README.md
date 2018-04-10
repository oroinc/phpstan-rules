# Oro's rules for PHPStan

This package contains a set of additional rules for [PHPStan - PHP Static Analysis Tool](https://github.com/phpstan/phpstan).

We use these rules at Oro, Inc. and ask anyone contributing code to Oro Products to follow them as well.

## Rules

### Unsafe SQL and DQL analysis

This rule analyzes source code for unsafe use of SQL and DQL queries.

It may require additional configuration files to perform the analysis. See [doc/QueryBuilderInjectionRule.md](/doc/QueryBuilderInjectionRule.md) for more details and recommendations.

## Installation

Add `oro/phpstan-rules` package to your [composer](https://getcomposer.org/) dependencies:

```
 composer require --dev oro/phpstan-rules
```

Include `rules.neon` in your project's PHPStan config:

```
includes:
    - vendor/oroinc/phpstan-rules/rules.neon
```

## Usage

Run check with:
 
```
./vendor/bin/phpstan analyze -c phpstan.neon <path_to_code> --autoload-file=<path_to_autoload.php>
```
 
To speedup the analysis you may run it in parallel (e.g. per package) using [GNU Parallel](https://www.gnu.org/software/parallel/) which is included by default in many Linux distributions:

```
mkdir logs;
ls <path_to_code>/ \
| parallel -j 4  "./vendor/bin/phpstan analyze -c phpstan.neon <path_to_code>/{} --autoload-file=`pwd`/app/autoload.php > logs/{}.log"
```

Your application should already have `autoload.php` file generated.
Please note that PHPStab does not support relative paths in `--autoload-file` option.

The results of the analysis should be available within a minute. Each result should be carefully checked and the necessary fixes should be applied.


## Contribute

Please referer to [Oro Community Guide](https://oroinc.com/orocommerce/doc/current/community/contribute) for information on how to contribute to this package and other Oro products. 
