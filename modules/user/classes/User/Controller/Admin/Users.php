<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2013 Bharat Mediratta
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
class User_Controller_Admin_Users extends Controller_Admin {
  public function action_index() {
    $view = new View_Admin("required/admin.html");
    $view->page_title = t("Users and groups");
    $view->page_type = "collection";
    $view->page_subtype = "admin_users";
    $view->content = new View("admin/users.html");

    // @todo: add this as a config option
    $page_size = Module::get_var("user", "page_size", 10);
    $page = Arr::get(Request::current()->query(), "page", "1");
    $user_count = DB::select()->from("users")->execute()->count();

    // Pagination info
    $view->page = $page;
    $view->page_size = $page_size;
    $view->children_count = $user_count;
    $view->max_pages = ceil($view->children_count / $view->page_size);

    $view->content->pager = new Pagination();
    $view->content->pager->initialize(
      array("query_string" => "page",
            "total_items" => $user_count,
            "items_per_page" => $page_size,
            "style" => "classic"));

    // Make sure that the page references a valid offset
    if ($page < 1) {
      HTTP::redirect(URL::query(array("page" => 1)));
    } else if ($page > $view->content->pager->total_pages) {
      HTTP::redirect(URL::query(array("page" => $view->content->pager->total_pages)));
    }

    // Join our users against the items table so that we can get a count of their items
    // in the same query.
    $view->content->users = ORM::factory("User")
      ->order_by("user.name", "ASC")
      ->limit($page_size)->offset($view->content->pager->sql_offset)->find_all();
    $view->content->groups = ORM::factory("Group")->order_by("name", "ASC")->find_all();

    print $view;
  }

  public function action_add_user() {
    Access::verify_csrf();

    $form = $this->_get_user_add_form_admin();
    try {
      $user = ORM::factory("User");
      $valid = $form->validate();
      $user->name = $form->add_user->inputs["name"]->value;
      $user->full_name = $form->add_user->full_name->value;
      $user->password = $form->add_user->password->value;
      $user->email = $form->add_user->email->value;
      $user->url = $form->add_user->url->value;
      $user->locale = $form->add_user->locale->value;
      $user->admin = $form->add_user->admin->checked;
      $user->validate();
    } catch (ORM_Validation_Exception $e) {
      // Translate ORM validation errors into form error messages
      foreach ($e->validation->errors() as $key => $error) {
        $form->add_user->inputs[$key]->add_error($error, 1);
      }
      $valid = false;
    }

    if ($valid) {
      $user->save();
      Module::event("user_add_form_admin_completed", $user, $form);
      Message::success(t("Created user %user_name", array("user_name" => $user->name)));
      JSON::reply(array("result" => "success"));
    } else {
      print JSON::reply(array("result" => "error", "html" => (string)$form));
    }
  }

  public function action_add_user_form() {
    print $this->_get_user_add_form_admin();
  }

  public function action_delete_user($id) {
    Access::verify_csrf();

    if ($id == Identity::active_user()->id || $id == User::guest()->id) {
      Access::forbidden();
    }

    $user = User::lookup($id);
    if (empty($user)) {
      throw HTTP_Exception::factory(404);
    }

    $form = $this->_get_user_delete_form_admin($user);
    if($form->validate()) {
      $name = $user->name;
      $user->delete();
    } else {
      JSON::reply(array("result" => "error", "html" => (string)$form));
    }

    $message = t("Deleted user %user_name", array("user_name" => $name));
    GalleryLog::success("user", $message);
    Message::success($message);
    JSON::reply(array("result" => "success"));
  }

  public function action_delete_user_form($id) {
    $user = User::lookup($id);
    if (empty($user)) {
      throw HTTP_Exception::factory(404);
    }
    $v = new View("admin/users_delete_user.html");
    $v->user = $user;
    $v->form = $this->_get_user_delete_form_admin($user);
    print $v;
  }

  public function action_edit_user($id) {
    Access::verify_csrf();

    $user = User::lookup($id);
    if (empty($user)) {
      throw HTTP_Exception::factory(404);
    }

    $form = $this->_get_user_edit_form_admin($user);
    try {
      $valid = $form->validate();
      $user->name = $form->edit_user->inputs["name"]->value;
      $user->full_name = $form->edit_user->full_name->value;
      if ($form->edit_user->password->value) {
        $user->password = $form->edit_user->password->value;
      }
      $user->email = $form->edit_user->email->value;
      $user->url = $form->edit_user->url->value;
      $user->locale = $form->edit_user->locale->value;
      if ($user->id != Identity::active_user()->id) {
        $user->admin = $form->edit_user->admin->checked;
      }

      $user->validate();
    } catch (ORM_Validation_Exception $e) {
      // Translate ORM validation errors into form error messages
      foreach ($e->validation->errors() as $key => $error) {
        $form->edit_user->inputs[$key]->add_error($error, 1);
      }
      $valid = false;
    }

    if ($valid) {
      $user->save();
      Module::event("user_edit_form_admin_completed", $user, $form);
      Message::success(t("Changed user %user_name", array("user_name" => $user->name)));
      JSON::reply(array("result" => "success"));
    } else {
      JSON::reply(array("result" => "error", "html" => (string) $form));
    }
  }

  public function action_edit_user_form($id) {
    $user = User::lookup($id);
    if (empty($user)) {
      throw HTTP_Exception::factory(404);
    }

    print $this->_get_user_edit_form_admin($user);
  }

  public function action_add_user_to_group($user_id, $group_id) {
    Access::verify_csrf();
    $group = Group::lookup($group_id);
    $user = User::lookup($user_id);
    $group->add("users", $user);
    $group->save();
  }

  public function action_remove_user_from_group($user_id, $group_id) {
    Access::verify_csrf();
    $group = Group::lookup($group_id);
    $user = User::lookup($user_id);
    $group->remove("users", $user);
    $group->save();
  }

  public function action_group($group_id) {
    $view = new View("admin/users_group.html");
    $view->group = Group::lookup($group_id);
    print $view;
  }

  public function action_add_group() {
    Access::verify_csrf();

    $form = $this->_get_group_add_form_admin();
    try {
      $valid = $form->validate();
      $group = ORM::factory("Group");
      $group->name = $form->add_group->inputs["name"]->value;
      $group->validate();
    } catch (ORM_Validation_Exception $e) {
      // Translate ORM validation errors into form error messages
      foreach ($e->validation->errors() as $key => $error) {
        $form->add_group->inputs[$key]->add_error($error, 1);
      }
      $valid = false;
    }

    if ($valid) {
      $group->save();
      Message::success(
        t("Created group %group_name", array("group_name" => $group->name)));
      JSON::reply(array("result" => "success"));
    } else {
      JSON::reply(array("result" => "error", "html" => (string)$form));
    }
  }

  public function action_add_group_form() {
    print $this->_get_group_add_form_admin();
  }

  public function action_delete_group($id) {
    Access::verify_csrf();

    $group = Group::lookup($id);
    if (empty($group)) {
      throw HTTP_Exception::factory(404);
    }

    $form = $this->_get_group_delete_form_admin($group);
    if ($form->validate()) {
      $name = $group->name;
      $group->delete();
    } else {
      JSON::reply(array("result" => "error", "html" => (string) $form));
    }

    $message = t("Deleted group %group_name", array("group_name" => $name));
    GalleryLog::success("group", $message);
    Message::success($message);
    JSON::reply(array("result" => "success"));
  }

  public function action_delete_group_form($id) {
    $group = Group::lookup($id);
    if (empty($group)) {
      throw HTTP_Exception::factory(404);
    }

    print $this->_get_group_delete_form_admin($group);
  }

  public function action_edit_group($id) {
    Access::verify_csrf();

    $group = Group::lookup($id);
    if (empty($group)) {
       throw HTTP_Exception::factory(404);
    }

    $form = $this->_get_group_edit_form_admin($group);
    try {
      $valid = $form->validate();
      $group->name = $form->edit_group->inputs["name"]->value;
      $group->validate();
    } catch (ORM_Validation_Exception $e) {
      // Translate ORM validation errors into form error messages
      foreach ($e->validation->errors() as $key => $error) {
        $form->edit_group->inputs[$key]->add_error($error, 1);
      }
      $valid = false;
    }

    if ($valid) {
      $group->save();
      Message::success(
        t("Changed group %group_name", array("group_name" => $group->name)));
      JSON::reply(array("result" => "success"));
    } else {
      $group->reload();
      Message::error(
        t("Failed to change group %group_name", array("group_name" => $group->name)));
      JSON::reply(array("result" => "error", "html" => (string) $form));
    }
  }

  public function action_edit_group_form($id) {
    $group = Group::lookup($id);
    if (empty($group)) {
      throw HTTP_Exception::factory(404);
    }

    print $this->_get_group_edit_form_admin($group);
  }

  /* User Form Definitions */
  private static function _get_user_edit_form_admin($user) {
    $form = new Forge(
      "admin/users/edit_user/$user->id", "", "post", array("id" => "g-edit-user-form"));
    $group = $form->group("edit_user")->label(t("Edit user"));
    $group->input("name")->label(t("Username"))->id("g-username")->value($user->name)
      ->error_messages("required", t("A name is required"))
      ->error_messages("conflict", t("There is already a user with that username"))
      ->error_messages("length", t("This name is too long"));
    $group->input("full_name")->label(t("Full name"))->id("g-fullname")->value($user->full_name)
      ->error_messages("length", t("This name is too long"));
    $group->password("password")->label(t("Password"))->id("g-password")
      ->error_messages("min_length", t("This password is too short"));
    $group->script("")
      ->text(
        '$("form").ready(function(){$(\'input[name="password"]\').user_password_strength();});');
    $group->password("password2")->label(t("Confirm password"))->id("g-password2")
      ->error_messages("matches", t("The passwords you entered do not match"))
      ->matches($group->password);
    $group->input("email")->label(t("Email"))->id("g-email")->value($user->email)
      ->error_messages("required", t("You must enter a valid email address"))
      ->error_messages("length", t("This email address is too long"))
      ->error_messages("email", t("You must enter a valid email address"));
    $group->input("url")->label(t("URL"))->id("g-url")->value($user->url)
      ->error_messages("url", t("You must enter a valid URL"));
    self::_add_locale_dropdown($group, $user);
    $group->checkbox("admin")->label(t("Admin"))->id("g-admin")->checked($user->admin);

    // Don't allow the user to control their own admin bit, else you can lock yourself out
    if ($user->id == Identity::active_user()->id) {
      $group->admin->disabled(1);
    }

    Module::event("user_edit_form_admin", $user, $form);
    $group->submit("")->value(t("Modify user"));
    return $form;
  }

  private static function _get_user_add_form_admin() {
    $form = new Forge("admin/users/add_user", "", "post", array("id" => "g-add-user-form"));
    $group = $form->group("add_user")->label(t("Add user"));
    $group->input("name")->label(t("Username"))->id("g-username")
      ->error_messages("required", t("A name is required"))
      ->error_messages("length", t("This name is too long"))
      ->error_messages("conflict", t("There is already a user with that username"));
    $group->input("full_name")->label(t("Full name"))->id("g-fullname")
      ->error_messages("length", t("This name is too long"));
    $group->password("password")->label(t("Password"))->id("g-password")
      ->error_messages("min_length", t("This password is too short"));
    $group->script("")
      ->text(
        '$("form").ready(function(){$(\'input[name="password"]\').user_password_strength();});');
    $group->password("password2")->label(t("Confirm password"))->id("g-password2")
      ->error_messages("matches", t("The passwords you entered do not match"))
      ->matches($group->password);
    $group->input("email")->label(t("Email"))->id("g-email")
      ->error_messages("required", t("You must enter a valid email address"))
      ->error_messages("length", t("This email address is too long"))
      ->error_messages("email", t("You must enter a valid email address"));
    $group->input("url")->label(t("URL"))->id("g-url")
      ->error_messages("url", t("You must enter a valid URL"));
    self::_add_locale_dropdown($group);
    $group->checkbox("admin")->label(t("Admin"))->id("g-admin");

    Module::event("user_add_form_admin", $user, $form);
    $group->submit("")->value(t("Add user"));
    return $form;
  }

  private static function _add_locale_dropdown(&$form, $user=null) {
    $locales = Locales::installed();
    foreach ($locales as $locale => $display_name) {
      $locales[$locale] = SafeString::of_safe_html($display_name);
    }

    // Put "none" at the first position in the array
    $locales = array_merge(array("" => t("« none »")), $locales);
    $selected_locale = ($user && $user->locale) ? $user->locale : "";
    $form->dropdown("locale")
      ->label(t("Language preference"))
      ->options($locales)
      ->selected($selected_locale);
  }

  private function _get_user_delete_form_admin($user) {
    $form = new Forge("admin/users/delete_user/$user->id", "", "post",
                      array("id" => "g-delete-user-form"));
    $group = $form->group("delete_user")->label(
      t("Delete user %name?", array("name" => $user->display_name())));
    $group->submit("")->value(t("Delete"));
    return $form;
  }

  /* Group Form Definitions */
  private function _get_group_edit_form_admin($group) {
    $form = new Forge("admin/users/edit_group/$group->id", "", "post", array("id" => "g-edit-group-form"));
    $form_group = $form->group("edit_group")->label(t("Edit group"));
    $form_group->input("name")->label(t("Name"))->id("g-name")->value($group->name)
      ->error_messages("required", t("A name is required"));
    $form_group->inputs["name"]->error_messages("conflict", t("There is already a group with that name"))
      ->error_messages("required", t("You must enter a group name"))
      ->error_messages("length",
                       t("The group name must be less than %max_length characters",
                         array("max_length" => 255)));
    $form_group->submit("")->value(t("Save"));
    return $form;
  }

  private function _get_group_add_form_admin() {
    $form = new Forge("admin/users/add_group", "", "post", array("id" => "g-add-group-form"));
    $form_group = $form->group("add_group")->label(t("Add group"));
    $form_group->input("name")->label(t("Name"))->id("g-name");
    $form_group->inputs["name"]->error_messages("conflict", t("There is already a group with that name"))
      ->error_messages("required", t("You must enter a group name"));
    $form_group->submit("")->value(t("Add group"));
    return $form;
  }

  private function _get_group_delete_form_admin($group) {
    $form = new Forge("admin/users/delete_group/$group->id", "", "post",
                      array("id" => "g-delete-group-form"));
    $form_group = $form->group("delete_group")->label(
      t("Are you sure you want to delete group %group_name?", array("group_name" => $group->name)));
    $form_group->submit("")->value(t("Delete"));
    return $form;
  }
}
