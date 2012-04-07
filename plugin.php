<?php
/*
Plugin Name: CLI by Fat Panda
Author: Fat Panda, LLC
Version: 0.1
Description: A command line interface for WordPress.
*/

add_action('init', function() use($argv) {
  if (@constant('DOING_CRON')) {
    return;
  }

  if (php_sapi_name() != 'cli') {
    return;
  }

  $file = 'index.php';
  $cmd = array_shift($argv);
  if (preg_match('/\.php$/', $cmd)) {
    $file = $cmd;
    $cmd = array_shift($argv);
  }
  $mode = array_shift($argv);
  array_unshift($argv, $file);

  if (!$cmd) {
    $cmd = 'help';
  }

  $cli = apply_filters("wp_cli_{$cmd}_{$mode}", false);

  if ($cli instanceof HashBang) {
    $cli->go();
    echo "\n";

  } else if ($cli === false) {
    echo "Invalid command. Use 'help' command for a list.\n";
  }

  exit;
});

add_filter('wp_cli_help_', function($argv) {
  global $wp_filter;

  $cmds = array();

  foreach($wp_filter as $tag => $cfg) {
    if (preg_match('/wp_cli_(.*)/i', $tag, $matches)) {
      list($cmd, $mode) = explode('_', $matches[1]);
      $cmds[$cmd][] = $mode;
    }
  }

  // sort by $cmds[$cmd]
  ksort($cmds);

  $cmds = array_map(function($cmd) use ($cmds) {
    $modes = $cmds[$cmd];
    sort($modes);
    return sprintf('%s    %s', $cmd, implode(', ', $modes));
  }, array_keys($cmds));

  echo implode("\n", $cmds);

  return true;
});

add_filter('wp_cli_opts_list', function($argv) {
  global $wpdb;

  $names = array_map(function($opt) {
    return $opt->option_name;
  }, $wpdb->get_results("SELECT option_name FROM {$wpdb->options}"));

  sort($names);

  echo implode("\n", $names);
});

add_filter('wp_cli_opts_get', function($argv) {
  $cmd = new HashBang(function($name) {
    $opt = get_option($name);
    if (!is_object($opt)) {
      echo $opt;
    } else {
      print_r($opt);
    }
  });

  $cmd->addArg('name');

  return $cmd;
});

add_filter('wp_cli_opts_update', function($argv) {
  $cmd = new HashBang(function($name, $value) {
    update_option($name, $value);
  });

  $cmd->addArg('name');
  $cmd->addArg('value');

  return $cmd;
});

add_filter('wp_cli_fbstats_url', function($argv) {
  $cmd = new HashBang(function($url) {
    $fb = new FatPandaStats_Facebook();
    $res = $fb->getURLInfo($url);
    print_r($res);
  });

  $cmd->addArg('url');
  
  return $cmd;
});

add_filter('wp_cli_fbstats_gapi', function($argv) {
  $cmd = new HashBang(function($url_endpoint) {
    $fb = new FatPandaStats_Facebook();
    $res = $fb->gapi($url_endpoint);
    print_r($res);
  });

  $cmd->addArg('url_endpoint');

  return $cmd;
});

add_filter('wp_cli_fbstats_getPagePosts', function($argv) {
  $cmd = new HashBang(function($page_id, $checkwindow) {
    $fb = new FatPandaStats_Facebook();
    $res = $fb->getPagePosts($page_id, $checkwindow);
    print_r($res);
  });

  $cmd->addArg('page_id');
  $cmd->addArg('pollwindow', false);

  return $cmd;
});

// https://github.com/Problematic/HashBang
class HashBang {

  protected $main;

  protected $switches = array();
  protected $args = array();
  protected $requiredArgs = array();
  protected $optionalArgs = array();

  protected $argv = array();
  protected $options = array();

  public function __construct(\Closure $main)
  {
      $this->main = $main;
  }

  public function go()
  {
      try {
          $this->initialize();
          $this->invoke($this->main);
      } catch (\HashBangException $e) {
          $this->handleException($e);
      }
  }

  public function addArg($arg, $required = true)
  {
      $this->args[$arg] = null;
      if ($required) {
          $this->requiredArgs[] = $arg;
      } else {
          $this->optionalArgs[] = $arg;
      }
  }

  public function addSwitch($short, $long = null)
  {
      $switch = array();
      $switch['short'] = $short;
      $switch['long'] = $long;

      $this->switches[] = $switch;
  }

  protected function invoke(\Closure $main)
  {
      $refl = new \ReflectionFunction($main);

      $args = $this->args;
      $args['argv'] = $this->argv;
      $args['options'] = $this->options;
      $argList = array();

      $params = $refl->getParameters();
      foreach ($params as $param) {
          $argList[$param->getPosition()] = isset($args[$param->name]) ? $args[$param->name] : null;
      }

      $refl->invokeArgs($argList);
  }

  /**
   * @return array values left in $argv after initialization
   */
  protected function initialize()
  {
      $argv = $GLOBALS['argv'];
      $file = array_shift($argv);
      $cmd = array_shift($argv);
      $mode = array_shift($argv);

      $count = count($this->switches);
      for($i = 0; $i < $count; $i++) {
          $short = ltrim($this->switches[$i]['short'], '-');
          $long = ltrim($this->switches[$i]['long'], '-');

          $opts = getopt($short, array($long));
          $value = null;
          foreach ($opts as $opt => $value) {
              $short = rtrim($short, ':');
              $long = rtrim($long, ':');
              $value = $value ?: true;
              $prefix = 1 === strlen($opt) ? '-' : '--';
              if (false !== $index = array_search($prefix.$opt, $argv)) {
                  unset($argv[$index]);
                  if (true !== $value) {
                      unset($argv[++$index]);
                  }
              }
          }

          $this->options[$long] = $this->options[$short] = $value;
      }
      unset($this->options['']); // artifact from switches without a long version

      while ($this->requiredArgs) {
          $required = array_shift($this->requiredArgs);
          $val = array_shift($argv);
          if (null === $val) {
              throw new \HashBangException(sprintf("'%s' is a required argument", $required));
          }
          $this->args[$required] = $val;
      }

      while ($this->optionalArgs) {
          $optional = array_shift($this->optionalArgs);
          $this->args[$optional] = array_shift($argv);
      }

      $this->argv = $argv;
  }

  protected function handleException(hashbangException $e)
  {
      echo "Error: {$e->getMessage()}\n";
  }

}

class HashBangException extends \Exception {}