<?php

/**
 * Adds clearfix to tabs.
 */
function scholar_base_menu_local_tasks() {
  $output = '';

  if (menu_primary_local_tasks()) {
    $output .= '<ul class="tabs primary clearfix">' . menu_primary_local_tasks() . '</ul>';
  }
  if (menu_secondary_local_tasks()) {
    $output .= '<ul class="tabs secondary clearfix">' . menu_secondary_local_tasks() . '</ul>';
  }

  return $output;
}

function scholar_base_id_safe($string) {
  // Replace with dashes anything that isn't A-Z, numbers, dashes, or underscores.
  $string = strtr(drupal_strtolower($string), array(' ' => '-', '_' => '-', '[' => '-', ']' => ''));
  $string = strtolower(preg_replace('/[^a-zA-Z0-9-]+/', '-', $string));
  // If the first character is not a-z, add 'id' in front.
  if (!ctype_lower($string{0})) { // Don't use ctype_alpha since it is locale aware.
    $string = 'id' . $string;
  }
  return $string;
}

function scholar_base_preprocess_page(&$vars, $hook) {

  $body_classes = array($vars['body_classes']);
  $original = array('left','right');
  $new = array('first','second');
  $body_classes = str_replace($original,$new,$body_classes);

  if (!$vars['is_front']) {
    // Add unique classes for each page and site section
    $path = drupal_get_path_alias($_GET['q']);
    list($section, ) = explode('/', $path, 2);
    $body_classes[] = scholar_base_id_safe('page-' . $path);
    $body_classes[] = scholar_base_id_safe('section-' . $section);
    if (arg(0) == 'node') {
      if (arg(1) == 'add') {
        if ($section == 'node') {
          array_pop($body_classes); // Remove 'section-node'
        }
        $body_classes[] = 'section-node-add'; // Add 'section-node-add'
      }
      elseif (is_numeric(arg(1)) && (arg(2) == 'edit' || arg(2) == 'delete')) {
        if ($section == 'node') {
          array_pop($body_classes); // Remove 'section-node'
        }
        $body_classes[] = 'section-node-' . arg(2); // Add 'section-node-edit' or 'section-node-delete'
      }
    }
  }

  if (module_exists('taxonomy') && $vars['node']->nid) {
    foreach (taxonomy_node_get_terms($vars['node']) as $term) {
      $body_classes[] = 'category-' . str_replace(' ', '-', scholar_base_id_safe($term->name));
    }
  }

  //Adds OpenScholar header region awareness to body classes
  $regions = array (
    'left' => $vars['header_left'],
    'main' => $vars['header_main'],
    'right' => $vars['header_right'],
  );

  $non_empty_regions = array_filter($regions, "__scholar_base_is_empty");
  $header_classes = 'header-';
  if (count($non_empty_regions)) {
    $header_classes .= implode('-', array_keys($non_empty_regions));
   }
  else {
    $header_classes .= 'none';
  }

  $body_classes[] = $header_classes;

  $vars['body_classes'] = implode(' ', $body_classes);
}


/**
 * Generates links for node teasers for easy edit/delete
 */
function scholar_base_node_links($links) {
  $output = array();
  foreach ($links as $link) {
    $options = $link;
    $options['attributes']['class'] = isset($link['attributes']['class']) ? $link['attributes']['class'] : 'node-links-button';
    //if(!is_array($options['query'])) $options['query'] = array();
    //$options['query']['destination'] = $_GET['q'];

    if (!empty($link['custom'])) {
      $output[]= l($link['title'], $link['href'], $options);
    }
    else {
      $output[]= l(t('Add !type', array('!type' => $link['title'])), $link['href'], $options);
    }
  }

  if($output){
    $output = theme('item_list', $output,  $title = NULL, $type = 'ul', $attributes = array("class" => "node-links"));
    //$output = '<div class = "context-links">' . $output . '</div>';
  }
  return $output;
}


function __scholar_base_is_empty($s){
  return $s ? TRUE : FALSE;
}


/**
 * Generates a themed set of links for node types associated with
 * the current active contexts.
 */
function scholar_base_context_links($links) {
  $output = array();
  foreach ($links as $link) {
    $options = $link;
    $options['attributes']['class'] = isset($link['attributes']['class']) ? $link['attributes']['class'] : 'context-button';
    //if(!is_array($options['query'])) $options['query'] = array();
    //$options['query']['destination'] = $_GET['q'];

    if (!empty($link['custom'])) {
      $output[]= l($link['title'], $link['href'], $options);
    }
    else {
      $output[]= l(t('Add !type', array('!type' => $link['title'])), $link['href'], $options);
    }
  }

  if($output){
    $output = theme('item_list', $output,  $title = NULL, $type = 'ul', $attributes = array("class" => "context-links"));
    //$output = '<div class = "context-links">' . $output . '</div>';
  }
  return $output;
}


function scholar_base_node_submitted($node){
  return t('@datetime',
   array(
    //'!username' => theme('username', $node),
     '@datetime' => format_date($node->created),
  ));
}