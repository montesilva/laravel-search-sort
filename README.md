LaravelSearchSort, a basic search and sort trait for Laravel 7 and 8
==========================================

LaravelSearchSort is a fork from nicolaslopezj/searchable. It's a trait for Laravel 7+ that adds a simple search, sort function to Eloquent Models.

LaravelSearchSort allows you to perform searches in a table giving priorities to each field for the table and it's relations,
and also sorting these results by given columns and directions.

This is not optimized for big searches, but sometimes you just need to make it simple (Although it is not slow).

# Installation

Simply add the package to your `composer.json` file and run `composer update`.

```
"montesilva/laravel-search-sort": "1.*"
```

You can also use this command:

```
composer require montesilva/laravel-search-sort
```


## Laravel 8 Support

For Laravel 8 ```config/database.php``` must be changed.

In ```config/database.php```, set mysql's ```'strict'``` to ```false```.

```php
'mysql' => [
    ...
    'strict' => false,
    ...
],
```

# Usage

Add the trait to your model and add your search and/or sort rules.

```php
use Montesilva\LaravelSearchSort\SearchSortTrait;

class User extends \Eloquent
{
    use SearchSortTrait;

    /**
     * Searchable rules.
     *
     * @var array
     */
    protected $search_sort = [
        /**
         * Columns and their priority in search results.
         * Columns with higher values are more important.
         * Columns with equal values have equal importance.
         *
         * @var array
         */
        'search_columns' => [
            'users.first_name' => 10,
            'users.last_name' => 10,
            'users.bio' => 2,
            'users.email' => 5,
            'posts.title' => 2,
            'posts.body' => 1,
        ],
        'sort_columns' => [
            'users.first_name', 'users.last_name', 'posts.title', 'posts.email'
        ],
        'joins' => [
            'posts' => ['users.id','posts.user_id'],
        ],
        'groupBy' => [
            'posts.id'
        ]
    ];

    public function posts()
    {
        return $this->hasMany('Post');
    }

}
```
## Search
Now you can search your model.

```php
// Simple search
$users = User::search($query)->get();

// Search and get relations
// It will not get the relations if you don't do this
$users = User::search($query)
            ->with('posts')
            ->get();
```

## Sort

Or you can sort your model.
```php
// Sort array with sortable column and direction (asc, desc) 
$sorts = [
    [
        'prop' => 'users.first_name',
        'dir' => 'asc'
    ]
];

// Simple sort
$users = User::sort($sorts)->get();

// Sort and get relations
// It will not get the relations if you don't do this
$users = User::sort($query)
            ->with('posts')
            ->get();
```

## Search Paginated

As easy as laravel default queries

```php
// Search with relations and paginate
$users = User::search($query)
            ->with('posts')
            ->paginate(20);
```

## Sort Paginated
```php
// Sort array with sortable column and direction (asc, desc) 
$sorts = [
    [
        'prop' => 'users.first_name',
        'dir' => 'asc'
    ]
];

// Search with relations and paginate
$users = User::sort($sorts)
            ->with('posts')
            ->paginate(20);
```

## Search and Sort

You can also search and sort at the same time
```php
// Sort array with sortable column and direction (asc, desc) 
$sorts = [
    [
        'prop' => 'users.first_name',
        'dir' => 'asc'
    ]
];

// Search and sort with relations and paginate
$users = User::searchSort($query, $sorts)
            ->with('posts')
            ->paginate(20);
```

## Mix queries

Search and Sort methods are compatible with any eloquent method. You can do things like this:

```php
// Search only active users
$users = User::where('status', 'active')
            ->search($query)
            ->paginate(20);
```

## Mix queries with joins before search and sort

You can join other tables first to have the join tables available 
to include them in any eloquent method

```php
// Add joins first
$builder = User::addJoins()->where('posts.title', 'Test');
// Search and sort without making joins in scope
$users = $builder::searchSort($query, $sorts, false)
            ->paginate(20);
```

```php
// Search or sort without making joins in search or sort scope
$users = User::addJoins()
                ->where('posts.title', 'Test')
                ->search($query, false)
                ->sort($sorts, false)
                ->with('posts')
                ->paginate(20); 
```

## Custom Threshold

The default threshold for accepted relevance is the sum of all attribute relevance divided by 4.
To change this value you can pass in a second parameter to search() like so:

```php
// Search with lower relevance threshold
$users = User::where('status', 'active')
            ->search($query, true, 0)
            ->paginate(20);
```

The above, will return all users in order of relevance.

## Entire Text search

By default, multi-word search terms are split and LaravelSearchSort searches for each word individually. Relevance plays a role in prioritizing matches that matched on multiple words. If you want to prioritize matches that include the multi-word search (thus, without splitting into words) you can enable full text search by setting the third value to true. Example:

```php
// Prioritize matches containing "John Doe" above matches containing only "John" or "Doe".
$users = User::search("John Doe", null, true, true)->get();
```

If you explicitly want to search for full text matches only, you can disable multi-word splitting by setting the fourth parameter to true.

```php
// Do not include matches that only matched "John" OR "Doe".
$users = User::search("John Doe", null, true, true, true)->get();
```

## Entire Text search sorted
```php
// Do not include matches that only matched "John" OR "Doe".
$users = User::searchSort("John Doe", $sorts, null, true, true, true)->get();
```

## Contributing

Anyone is welcome to contribute. Fork, make your changes, and then submit a pull request.

