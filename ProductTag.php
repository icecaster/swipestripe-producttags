<?php

class ProductTag extends DataObject {

	private static $db = array(
		"Title" => "Varchar",
		"SortOrder" => "Int"
	);

	private static $has_one = array(
		"Group" => "ProductTagGroup"
	);

	private static $many_many = array(
		"Products" => "Product"
	);

	private static $summary_fields = array(
		"Group.Title" => "Group",
		"Title" => "Title",
	);

	private static $extensions = array(
		"URLSegmented"
	);

	private static $default_sort = "SortOrder";


	function NiceTitle() {
		return $this->Group()->Title.": ".$this->Title;
	}

	static function enable() {
		Object::add_extension('Product', 'ProductTag_ProductExtension');
		Object::add_extension('ProductCategory_Controller', 'ProductTag_ProductCategory_Controller_Extension');
	}

}

class ProductTagGroup extends DataObject {

	private static $db = array(
		"Title" => "Varchar",
		"SortOrder" => "Int"
	);

	private static $has_many = array(
		"Tags" => "ProductTag"
	);

	private static $default_sort = "SortOrder";


	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$conf = GridFieldConfig_RelationEditor::create(500);
		$conf->addComponent(new GridFieldSortableRows("SortOrder"));

		$fields->removeByName("Tags");
		$fields->removeByName("SortOrder");

		$fields->addFieldToTab("Root.Main", new GridField("Tags", "Product Tags", $this->Tags(), $conf));

		return $fields;
	}

}

class ProductTag_ProductExtension extends DataExtension {

	private static $belongs_many_many = array(
		"Tags" => "ProductTag"
	);
	
	function updateProductCMSFields(FieldList $fields) {
		$fields->addFieldToTab("Root.Main", ListboxField::create("Tags", "Filter Tags")
			->setSource(ProductTag::get()->map("ID", "NiceTitle")->toArray())
			->setMultiple(true), 
		"Content");
	}

	function TagGroupIDs() {
		$groupIDs = $this->owner->Tags()->map("GroupID", "GroupID")->toArray();

		return $groupIDs;
	}

	function TagIDs() {
		$groupIDs = $this->owner->Tags()->map("ID", "ID")->toArray();
		return $groupIDs;
	}

}

class ProductTag_ProductCategory_Controller_Extension extends Extension {

	static $allowed_actions = array(
		"ProductFilterForm",
		"filterProducts"
	);


	function TagIDs() {
		$tags_map = $this->owner->Products()->map("ID", "TagIDs")->toArray();
		$tagIDs = array();
		foreach($tags_map as $_productID => $_tagMap) {
			foreach($_tagMap as $_tagID) {
				$tagIDs[$_tagID] = $_tagID;
			}
		}
		return $tagIDs;
	}

	function TagGroups() {
		$groups_map = $this->owner->Products()->map("ID", "TagGroupIDs")->toArray();
		$groupIDs = array();
		foreach($groups_map as $_productID => $_groupMap) {
			foreach($_groupMap as $_tagID => $_groupIDs) {
				$groupIDs[$_groupIDs] = $_groupIDs;
			}
		}
		if($groupIDs) {
			return ProductTagGroup::get()->byIDs($groupIDs);
		}
	}

	function ProductFilterForm() {
		if(!$this->owner->TagGroups()) {
			return false;
		}
		$currentFilters = $this->Filters();
		$tagIDs = $this->TagIDs();

		$fields = array();
		

		foreach($this->owner->TagGroups() as $group) {
			
			$field = CheckboxSetField::create("Filter[{$group->ID}]", $group->Title,
				$group->Tags()->byIDs($this->TagIDs())->map("ID", "Title")->toArray()
			);

			if(isset($currentFilters[$group->ID])) {
				$value = implode(",", (array)$currentFilters[$group->ID]);
				$field->setValue($value);
			}

			$fields[] = $field;
		}

		$fields = FieldList::create($fields);

		$actions = FieldList::create(
			FormAction::create("filterProducts", "Filter")
		);

		$form = Form::create($this->owner, __FUNCTION__, $fields, $actions);
		$form->setEncType(Form::ENC_TYPE_MULTIPART);
		$form->disableSecurityToken();
		$form->setFormMethod("get");
		return $form;
	}

	function Filters() {
		$ret = array();
		$vars = (array)$this->owner->request->getVar("Filter");
		foreach($vars as $groupID => $_tags) {
			$tags = array();
			foreach($_tags as $_tag) {
				$tags[(int)$_tag] = (int)$_tag;
			}

			$ret[(int)$groupID] = $tags;
		}
		return $ret;
	}

	function filterProducts($data, $form) {

		$currentFilters = $this->Filters();
		$products = $this->owner->Products();

		if($currentFilters) {
			foreach($currentFilters as $groupID => $tags) {
				$tagIDs_sql = implode(",", $tags);
				$products = $products->where("SiteTree_Live.ID IN (SELECT ProductID FROM ProductTag_Products WHERE ProductTagID IN ({$tagIDs_sql}))");
				$products = PaginatedList::create($products, $this->owner->request)
					->setPageLength($this->owner->config()->get("products_per_page"));
			}
		}

		return $this->owner->customise(array("Products" => $products));
	}

}


class ProductTag_Admin extends ModelAdmin {

	static $managed_models = array(
		"ProductTag",
		"ProductTagGroup"
	);

	static $url_segment = "tags";
	static $menu_title = "Filter Tags";


	public function getEditForm($id = null, $fields = null) {
		$form = parent::getEditForm($id, $fields);
		if(singleton($this->modelClass)->hasField("SortOrder")) {
			$gridField = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
			if($gridField instanceof GridField) {
				$gridField->getConfig()->addComponent(new GridFieldSortableRows("SortOrder"));
				$gridField->getConfig()->removeComponentsByType(new GridFieldPaginator());
				$gridField->getConfig()->addComponent(new GridFieldPaginator(500));
			}
		}
		return $form;
	}

}