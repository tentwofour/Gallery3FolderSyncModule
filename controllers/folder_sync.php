<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2012 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Folder_Sync_Controller extends Controller {
  // TODO Clean up
  /*public function browse($id) {
    $paths = unserialize(module::get_var("folder_sync", "authorized_paths"));
    foreach (array_keys($paths) as $path) {
      $files[] = $path;
    }

    $item = ORM::factory("item", $id);
    $view = new View("folder_sync_tree_dialog.html");
    $view->item = $item;
    $view->tree = new View("folder_sync_tree.html");
    $view->tree->files = $files;
    $view->tree->parents = array();
    print $view;
  }

  public function children() {
    $path = Input::instance()->get("path");

    $tree = new View("folder_sync_tree.html");
    $tree->files = array();
    $tree->parents = array();

    // Make a tree with the parents back up to the authorized path, and all the children under the
    // current path.
    if (folder_sync::is_valid_path($path)) {
      $tree->parents[] = $path;
      while (folder_sync::is_valid_path(dirname($tree->parents[0])."/")) {
        array_unshift($tree->parents, dirname($tree->parents[0])."/");
      }
      
      if(folder_sync::is_too_deep($path))
        continue;

      $glob_path = str_replace(array("{", "}", "[", "]"), array("\{", "\}", "\[", "\]"), $path);
      foreach (glob("$glob_path*") as $file) {
        if (!is_readable($file)) {
          continue;
        }
        if (!is_dir($file)) {
          $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
          if (!in_array($ext, array("gif", "jpeg", "jpg", "png", "flv", "mp4", "m4v"))) {
            continue;
          }
        }
        else
          $file .= "/";

        $tree->files[] = $file;
      }
    } else {
      // Missing or invalid path; print out the list of authorized path
      $paths = unserialize(module::get_var("folder_sync", "authorized_paths"));
      foreach (array_keys($paths) as $path) {
        $tree->files[] = $path;
      }
    }
    print $tree;
  }*/

  static function cron()
  {
    $owner_id = 2;
	
	$debug = !empty($_SERVER['argv']) && isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == "debug";
    
    // Login as Admin
	$debug and print "Starting user session\n";
    $session = Session::instance();
    $session->delete("user");
    auth::login(IdentityProvider::instance()->admin_user());

    // check if some folders are still unprocessed from previous run
    $entry = ORM::factory("folder_sync_entry")
      ->where("is_directory", "=", 1)
      ->where("checked", "=", 0)
      ->order_by("id", "ASC")
      ->find();
    if (!$entry->loaded())
    {
	  $debug and print "Adding default folders\n";
      $paths = unserialize(module::get_var("folder_sync", "authorized_paths"));
      foreach (array_keys($paths) as $path) {
        if (folder_sync::is_valid_path($path)) {
          $path = rtrim($path, "/");

	      $debug and print " * $path\n";

          $entry = ORM::factory("folder_sync_entry")
            ->where("is_directory", "=", 1)
            ->where("path", "=", $path)
            ->find();
           
          if($entry && $entry->loaded())
          {
            $entry->checked = 0;
            $entry->save();
          }
          else
          {
            $entry = ORM::factory("folder_sync_entry");
            $entry->path = $path;
            $entry->is_directory = 1;
            $entry->parent_id = null;
            $entry->item_id = module::get_var("folder_sync", "destination_album_id", 1);
            $entry->md5 = '';
            $entry->save();
          }
        }
      }
    }

    // Scan and add files
	$debug and print "Starting the loop\n";
    $done = false;
    $limit = 500;
    while(!$done && $limit > 0) {
	  $debug and print "Loop started: Limit = $limit\n";
      $entry = ORM::factory("folder_sync_entry")
        ->where("is_directory", "=", 1)
        ->where("checked", "=", 0)
        ->order_by("id", "ASC")
        ->find();

      if ($entry->loaded()) {
		// get the parrent
		$parent = ORM::factory("item", $entry->item_id);
		if(!$parent->loaded())
		{
		  $debug and print "Deleting entry #{$entry->id} pointing to missing item #{$entry->item_id}\n";
		  //$entry->delete();
		  //continue;
		}

  	    $debug and print "Scanning folder: {$entry->path}\n";
        $child_paths = glob(preg_quote($entry->path) . "/*");
        if (!$child_paths) {
          $child_paths = glob("{$entry->path}/*");
        }
        foreach ($child_paths as $child_path) {
          $name = basename($child_path);
          $title = item::convert_filename_to_title($name);

	      $debug and print "Found $child_path...";
		  
          if (is_dir($child_path)) {
			$debug and print "folder\n";
            $entry_exists = ORM::factory("folder_sync_entry")
              ->where("is_directory", "=", 1)
              ->where("path", "=", $child_path)
              ->find();

            if($entry_exists && $entry_exists->loaded()) {
			  $debug and print "Folder is already imported, marked to re-sync.\n";
              $entry_exists->checked = 0;
              $entry_exists->save();
            } else {
			  $debug and print "Adding new folder.\n";
              $album = ORM::factory("item");
              $album->type = "album";
              $album->parent_id = $parent->id;
              $album->name = $name;
              $album->title = $title;
              $album->owner_id = $owner_id;
              $album->sort_order = $parent->sort_order;
              $album->sort_column = $parent->sort_column;
              $album->save();

              $child_entry = ORM::factory("folder_sync_entry");
              $child_entry->path = $child_path;
              $child_entry->parent_id = $entry->id;
              $child_entry->item_id = $album->id;
              $child_entry->is_directory = 1;
              $child_entry->md5 = "";
              $child_entry->save();
            }
          } else {
			$debug and print "file\n";
            $ext = strtolower(pathinfo($child_path, PATHINFO_EXTENSION));
            if (!in_array($ext, legal_file::get_extensions()) || !filesize($child_path))
            {
              // Not importable, skip it.
			  $debug and print "File is incompatible. Skipping.\n";
              continue;
            }
            
            // check if file was already imported
            $entry_exists = ORM::factory("folder_sync_entry")
              ->where("is_directory", "=", 0)
              ->where("path", "=", $child_path)
              ->find();

            if($entry_exists && $entry_exists->loaded())
            {
			  $debug and print "Image is already imported...";
              if(empty($entry_exists->added) || empty($entry_exists->md5) || $entry_exists->added != filemtime($child_path) || $entry_exists->md5 != md5_file($child_path))
              {
                $item = ORM::factory("item", $entry_exists->item_id);
                if($item->loaded())
                {
                  $item->set_data_file($child_path);
				  $debug and print "updating.\n";
                try
                    {
                    $item->save();
                    }
                    catch(ORM_Validation_Exception $e)
                    {
                    print("Error saving the image (ID = {$item->id}) with the new data file.\n");
                    exit();
                    }
                }
                else
                {
				  $debug and print "deleting.\n";
                  $entry_exists->delete();
                }
              }
			  else
			  {
			    $debug and print "skipping.\n";
			  }
              // since it's an update, don't count too much towards the limit
              $limit-=0.25;
            }
            else
            {
              if (in_array($ext, legal_file::get_photo_extensions())) {
				$debug and print "Adding new photo.\n";
                $item = ORM::factory("item");
                $item->type = "photo";
                $item->parent_id = $parent->id;
                $item->set_data_file($child_path);
                $item->name = $name;
                $item->title = $title;
                $item->owner_id = $owner_id;
                $item->save();
              } else if (in_array($ext, legal_file::get_movie_extensions())) {
				$debug and print "Adding new video.\n";
                $item = ORM::factory("item");
                $item->type = "movie";
                $item->parent_id = $parent->id;
                $item->set_data_file($child_path);
                $item->name = $name;
                $item->title = $title;
                $item->owner_id = $owner_id;
                $item->save();
              }

              $entry_exists = ORM::factory("folder_sync_entry");
              $entry_exists->path = $child_path;
              $entry_exists->parent_id = $entry->id;  // null if the parent was a staging dir
              $entry_exists->is_directory = 0;
              $entry_exists->md5 = md5_file($child_path);
              $entry_exists->added = filemtime($child_path);
              $entry_exists->item_id = $item->id;
              $entry_exists->save();

              $limit--;
            }
          }
          // Did we hit the limit?
          if($limit <= 0)
		  {
		    $debug and print "Reached the limit. Exiting.\n";
            exit;
		  }
        }

        // We've processed this entry unless we reached a limit.
        if($limit > 0)
        {
          $entry->checked = 1;
          $entry->save();
        }
      } else {
        $done = true;
	    $debug and print "All folders are processed. Exiting.\n";
      }
    }
    
    // process deletes
    if(module::get_var("folder_sync", "process_deletes", false))
    {
      $entries = ORM::factory("folder_sync_entry")
        ->order_by("id", "ASC")
        ->find_all();
      foreach($entries as $entry)
      {
        if(!file_exists($entry->path) && $entry->item_id > 1)
        {
					$item = ORM::factory("item", $entry->item_id);
					if($item->loaded())
						$item->delete();
        }
      }
    }
    exit;
  }
}
