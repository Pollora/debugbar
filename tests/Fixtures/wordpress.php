<?php

declare(strict_types=1);

/*
 * The few WordPress classes the collectors read, reduced to what they read.
 * WordPress is not loaded in these tests; each class keeps the real name and
 * the real public members, and is only defined when nothing else defined it.
 */

if (! class_exists('_WP_Dependency')) {
    class _WP_Dependency
    {
        public $handle;

        public $src;

        public $deps = [];

        public $ver;

        public $args;

        public $extra = [];

        public function __construct(...$args)
        {
            [$this->handle, $this->src, $this->deps, $this->ver, $this->args] = array_pad($args, 5, false);
            $this->deps = (array) $this->deps;
        }
    }
}

if (! class_exists('WP_Dependencies')) {
    class WP_Dependencies
    {
        public $registered = [];

        public $queue = [];

        public $done = [];

        public function get_data($handle, $key)
        {
            return $this->registered[$handle]->extra[$key] ?? false;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private $code = '', private $message = '') {}

        public function get_error_message($code = '')
        {
            return $this->message;
        }
    }
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID = 0;

        public $post_type = 'post';

        public $post_title = '';
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public $term_id = 0;

        public $taxonomy = 'category';

        public $name = '';
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public $ID = 0;

        public $display_name = '';
    }
}

if (! class_exists('WP_Post_Type')) {
    class WP_Post_Type
    {
        public $name = '';
    }
}

if (! class_exists('WP_Block_Type')) {
    class WP_Block_Type
    {
        public $render_callback = null;

        public function __construct(public $name = '') {}
    }
}

if (! class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry
    {
        /** @var array<string, WP_Block_Type> Test seam: the real class keeps these private */
        public array $registered_block_types = [];

        private static ?self $instance = null;

        public static function get_instance()
        {
            return self::$instance ??= new self;
        }

        public function get_registered($name)
        {
            return $this->registered_block_types[$name] ?? null;
        }
    }
}
