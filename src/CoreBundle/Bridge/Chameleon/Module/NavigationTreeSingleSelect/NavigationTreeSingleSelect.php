<?php

/*
 * This file is part of the Chameleon System (https://www.chameleonsystem.com).
 *
 * (c) ESONO AG (https://www.esono.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChameleonSystem\CoreBundle\Bridge\Chameleon\Module\NavigationTreeSingleSelect;

use ChameleonSystem\CoreBundle\DataModel\BackendTreeNodeDataModel;
use ChameleonSystem\CoreBundle\Factory\BackendTreeNodeFactory;
use ChameleonSystem\CoreBundle\Service\LanguageServiceInterface;
use ChameleonSystem\CoreBundle\Util\FieldTranslationUtil;
use ChameleonSystem\CoreBundle\Util\InputFilterUtilInterface;
use ChameleonSystem\CoreBundle\Util\UrlUtil;
use Doctrine\DBAL\Connection;
use MTPkgViewRendererAbstractModuleMapper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Translation\TranslatorInterface;
use TGlobal;
use TTools;

class NavigationTreeSingleSelect extends MTPkgViewRendererAbstractModuleMapper
{
    /**
     * The mysql tablename of the tree.
     *
     * @var string
     */
    private $treeTable = 'cms_tree';

    /**
     * @var Connection
     */
    private $dbConnection;
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var InputFilterUtilInterface
     */
    private $inputFilterUtil;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var UrlUtil
     */
    private $urlUtil;
    /**
     * @var BackendTreeNodeFactory
     */
    private $backendTreeNodeFactory;

    /**
     * @var string
     */
    protected $activeNodeId;

    /**
     * @var bool
     */
    private $isPortalSelectMode;

    /**
     * @var bool
     */
    private $isPortalHomeNodeSelectMode;

    /**
     * Nodes that should not be assignable or that should have only a
     * restricted context menu.
     *
     * @var array
     */
    protected $restrictedNodes = [];

    /**
     * @var TTools
     */
    private $tools;

    /**
     * @var TGlobal
     */
    private $global;

    /**
     * @var FieldTranslationUtil
     */
    private $fieldTranslationUtil;

    /**
     * @var \TdbCmsLanguage
     */
    private $editLanguage;

    public function __construct(
        Connection $dbConnection,
        EventDispatcherInterface $eventDispatcher,
        InputFilterUtilInterface $inputFilterUtil,
        UrlUtil $urlUtil,
        BackendTreeNodeFactory $backendTreeNodeFactory,
        TranslatorInterface $translator,
        TTools $tools,
        TGlobal $global,
        FieldTranslationUtil $fieldTranslationUtil,
        LanguageServiceInterface $languageService
    ) {
        $this->dbConnection = $dbConnection;
        $this->eventDispatcher = $eventDispatcher;
        $this->inputFilterUtil = $inputFilterUtil;
        $this->urlUtil = $urlUtil;
        $this->backendTreeNodeFactory = $backendTreeNodeFactory;
        $this->translator = $translator;
        $this->tools = $tools;
        $this->global = $global;
        $this->fieldTranslationUtil = $fieldTranslationUtil;

        $this->editLanguage = $languageService->getActiveEditLanguage();
    }

    /**
     * {@inheritdoc}
     */
    public function Accept(\IMapperVisitorRestricted $visitor, $cachingEnabled, \IMapperCacheTriggerRestricted $cacheTriggerManager)
    {
        $fieldName = $this->inputFilterUtil->getFilteredGetInput('fieldName', '');
        $this->activeNodeId = $this->inputFilterUtil->getFilteredGetInput('id', '');
        $portalSelectMode = $this->inputFilterUtil->getFilteredGetInput('portalSelectMode', '');
        $visitor->SetMappedValue('portalSelectMode', $portalSelectMode);

        $pagedef = $this->inputFilterUtil->getFilteredGetInput('pagedef', 'navigationTreeSingleSelect');
        if ('' !== $portalSelectMode) {
            $tableName = 'cms_portal';
            $currentRecordId = $this->inputFilterUtil->getFilteredGetInput('portalId', '');
        } else {
            $tableName = 'cms_tpl_page';
            $currentRecordId = $this->inputFilterUtil->getFilteredGetInput('currentPageId', '');
        }

        $this->restrictedNodes = $this->getPortalNavigationStartNodes();

        $rootTreeId = $this->getPortalTreeRootNodeId();
        if ('' !== $rootTreeId) {
            $rootNode = new \TdbCmsTree();
            $rootNode->SetLanguage(\TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
            $rootNode->Load($rootTreeId);
            $visitor->SetMappedValue('breadcrumbStorageHTML', $this->createPageBreadcrumbs($rootNode, $fieldName));
        }

        $visitor->SetMappedValue('fieldName', $fieldName);

        $treeNodesAjaxUrl = $this->urlUtil->getArrayAsUrl(
            [
                'pagedef' => $pagedef,
                'module_fnc' => [
                        'contentmodule' => 'ExecuteAjaxCall',
                    ],
                '_fnc' => 'getTreeNodes',
                'activeNodeId' => $this->activeNodeId,
                'rootTreeId' => $rootTreeId,
                'fieldName' => $fieldName,
                'portalSelectMode' => $portalSelectMode,
            ],
            PATH_CMS_CONTROLLER.'?',
            '&'
        );
        $visitor->SetMappedValue('treeNodesAjaxUrl', $treeNodesAjaxUrl);

        $updateSelectionUrl = $this->urlUtil->getArrayAsUrl(
            [
                'pagedef' => $pagedef,
                'module_fnc' => [
                        'contentmodule' => 'ExecuteAjaxCall',
                    ],
                '_fnc' => 'updateSelection',
                'table' => $tableName,
                'currentRecordId' => $currentRecordId,
                'fieldName' => $fieldName,
            ],
            PATH_CMS_CONTROLLER.'?',
            '&'
        );
        $visitor->SetMappedValue('updateSelectionUrl', $updateSelectionUrl);
    }

    /**
     * {@inheritdoc}
     */
    protected function DefineInterface()
    {
        parent::DefineInterface();
        $this->methodCallAllowed[] = 'getTreeNodes';
        $this->methodCallAllowed[] = 'updateSelection';
    }

    protected function getTreeNodes(): array
    {
        $portalSelectMode = $this->inputFilterUtil->getFilteredGetInput('portalSelectMode', '');
        $this->isPortalSelectMode = 'portalSelect' === $portalSelectMode ? true : false;
        $this->isPortalHomeNodeSelectMode = 'portalHomePage' === $portalSelectMode ? true : false;  //also 404-page-selection

        $this->activeNodeId = $this->inputFilterUtil->getFilteredGetInput('activeNodeId', '');
        $rootTreeId = $this->inputFilterUtil->getFilteredGetInput('rootTreeId', '');
        if ('' === $rootTreeId) {
            return [];
        }
        $rootNode = new \TdbCmsTree();
        $rootNode->SetLanguage(\TdbCmsUser::GetActiveUser()->GetCurrentEditLanguageID());
        $rootNode->Load($rootTreeId);

        $treeData[] = $this->createTreeDataModel($rootNode, 0);

        return $treeData;
    }

    protected function updateSelection(): ?string
    {
        $tableName = $this->inputFilterUtil->getFilteredGetInput('table', '');
        $nodeId = $this->inputFilterUtil->getFilteredGetInput('nodeId', '');
        $currentRecordId = $this->inputFilterUtil->getFilteredGetInput('currentRecordId', '');
        $fieldName = $this->inputFilterUtil->getFilteredGetInput('fieldName', '');

        if ('' === $tableName || '' === $currentRecordId || '' === $fieldName) {
            return null;
        }

        $tableEditor = $this->tools->GetTableEditorManager($tableName, $currentRecordId);
        $tableEditor->SaveField($fieldName, $nodeId);

        return $nodeId;
    }

    private function getPortalTreeRootNodeId(): string
    {
        $portalId = $this->inputFilterUtil->getFilteredGetInput('portalID', '');

        if ('' === $portalId) {
            return \TCMSTreeNode::TREE_ROOT_ID;
        }

        $portal = new \TdbCmsPortal();
        if (false === $portal->Load($portalId)) {
            return \TCMSTreeNode::TREE_ROOT_ID;
        }

        return $portal->fieldMainNodeTree;
    }

    private function createTreeDataModel(\TdbCmsTree $node, int $level, string $path = ''): BackendTreeNodeDataModel
    {
        $treeNodeDataModel = $this->backendTreeNodeFactory->createTreeNodeDataModelFromTreeRecord($node);
        $treeNodeDataModel->setName($this->translateNodeName($treeNodeDataModel->getName(), $node));
        $this->setTypeAndAttributes($treeNodeDataModel, $node);

        if (0 === $level) {
            $treeNodeDataModel->setOpened(true);
        }

        if (true === $this->isPortalSelectMode) {
            $portalLevel = 1;
            if ($portalLevel !== $level) {
                $treeNodeDataModel->setDisabled(true);
                $treeNodeDataModel->addListHtmlClass('no-checkbox');
            }
        } elseif (true === $this->isPortalHomeNodeSelectMode) {
            $portalLevel = 1;
            if ($level <= $portalLevel) {
                $treeNodeDataModel->setDisabled(true);
                $treeNodeDataModel->addListHtmlClass('no-checkbox');
            }
        } else {
            $portalLevel = 0;
            $navigationLevel = $portalLevel + 1;
            if ($level <= $navigationLevel) {
                $treeNodeDataModel->setDisabled(true);
                $treeNodeDataModel->addListHtmlClass('no-checkbox');
            }
        }

        ++$level;
        $children = $node->GetChildren(true);
        while ($child = $children->Next()) {
            $childTreeNodeObj = $this->createTreeDataModel($child, $level, $path);
            $treeNodeDataModel->addChildren($childTreeNodeObj);
        }

        return $treeNodeDataModel;
    }

    private function translateNodeName(string $name, \TdbCmsTree $node): string
    {
        $node->SetLanguage($this->editLanguage->id);

        if ('' === $name) {
            $name = $this->global->OutHTML($this->translator->trans('chameleon_system_core.text.unnamed_record'));
        }

        if (false === CMS_TRANSLATION_FIELD_BASED_EMPTY_TRANSLATION_FALLBACK_TO_BASE_LANGUAGE) {
            return $name;
        }

        if ($this->fieldTranslationUtil->isTranslationNeeded($this->editLanguage)) {
            $nodeNameFieldName = $this->fieldTranslationUtil->getTranslatedFieldName($this->treeTable, 'name', $this->editLanguage);

            if ('' === $node->sqlData[$nodeNameFieldName]) {
                $name .= ' <span class="bg-danger px-1"><i class="fas fa-language" title="'.$this->translator->trans('chameleon_system_core.cms_module_table_editor.not_translated').'"></i></span>';
            }
        }

        return $name;
    }

    private function setTypeAndAttributes(BackendTreeNodeDataModel $treeNodeDataModel, \TdbCmsTree $node): void
    {
        $treeNodeDataModel->setType('');

        $children = $node->GetChildren(true);
        if ($children->Length() > 0) {
            $treeNodeDataModel->setType('folder');
        }
        if (true === $node->fieldHidden) {
            $this->addIconToTreeNode($treeNodeDataModel, 'nodeHidden', 'fas fa-eye-slash');
            $treeNodeDataModel->addLinkHtmlClass('node-hidden');
        }
        if ('' !== $node->sqlData['link']) {
            $this->addIconToTreeNode($treeNodeDataModel, 'externalLink', 'fas fa-external-link-alt');

            return;
        }

        if (true === $this->isPortalSelectMode && $node->id === $this->activeNodeId) {
            $treeNodeDataModel->setSelected(true);
        }

        $this->setPageAttributes($treeNodeDataModel, $node);
    }

    private function addIconToTreeNode(BackendTreeNodeDataModel $treeNodeDataModel, string $type, string $fontawesomeIcon): void
    {
        if ('' === $treeNodeDataModel->getType()) {
            $treeNodeDataModel->setType($type);
        } else {
            $treeNodeDataModel->addFurtherIconHTML('<i class="'.$fontawesomeIcon.' mr-2"></i>');
        }
    }

    private function setPageAttributes(BackendTreeNodeDataModel $treeNodeDataModel, \TdbCmsTree $node): void
    {
        $linkedPageOfNode = $node->GetLinkedPageObject(true);
        if (false === $linkedPageOfNode) {
            if ('' === $treeNodeDataModel->getType()) {
                $this->addIconToTreeNode($treeNodeDataModel, 'noPage', 'fas fa-genderless');
                $this->disableSelectionWysiwyg($treeNodeDataModel);
            }

            return;
        }

        $this->addIconToTreeNode($treeNodeDataModel, 'page', 'far fa-file');
        $treeNodeDataModel->addListAttribute('isPageId', $linkedPageOfNode->id);

        if (true === $linkedPageOfNode->fieldExtranetPage) {
            $treeNodeDataModel->addLinkHtmlClass('locked');
            $this->addIconToTreeNode($treeNodeDataModel, 'locked', 'fas fa-user-lock');
            if (false === $node->fieldShowExtranetPage) {
                $this->addIconToTreeNode($treeNodeDataModel, 'extranetpageHidden', 'far fa-eye-slash');
                $treeNodeDataModel->addLinkHtmlClass('extranetpage-hidden');
            }
        }
        $this->setCheckStatus($treeNodeDataModel, $node->id);
    }

    protected function setCheckStatus(BackendTreeNodeDataModel $treeNodeDataModel, $nodeId): void
    {
        if ($this->activeNodeId === $nodeId) {
            $treeNodeDataModel->setSelected(true);
        } else {
            if (false === $this->isPortalHomeNodeSelectMode) {
                $treeNodeDataModel->setDisabled(true);
                $treeNodeDataModel->addListHtmlClass('no-checkbox');
            }
        }
    }

    protected function disableSelectionWysiwyg(BackendTreeNodeDataModel $treeNodeDataModel): void
    {
    }

    private function createPageBreadcrumbs(\TdbCmsTree $node, $fieldName, $path = ''): string
    {
        $path .= '<li class="breadcrumb-item">'.$fieldName.'</li>';
        $pageBreadcrumbsHTML = '<div id="'.$fieldName.'_tmp_path_'.$node->id.'" style="display:none;"><ol class="breadcrumb pl-0"><li class="breadcrumb-item"><i class="fas fa-sitemap"></i></li>'.$path.'</ol></div>'."\n";

        $children = $node->GetChildren(true);
        while ($child = $children->Next()) {
            $pageBreadcrumbsHTML .= $this->createPageBreadcrumbs($child, $fieldName, $path);
        }

        return $pageBreadcrumbsHTML;
    }

    /**
     * Fetches a list of all restricted nodes (of all portals).
     * A restricted node is a start navigation node.
     */
    private function getPortalNavigationStartNodes(): array
    {
        $portalList = \TdbCmsPortalList::GetList();

        $restrictedNodes = [];
        while ($portal = $portalList->Next()) {
            $restrictedNodes[] = $portal->fieldMainNodeTree;
            $navigationList = $portal->GetFieldPropertyNavigationsList();
            while ($navigation = $navigationList->Next()) {
                $restrictedNodes[] = $navigation->fieldTreeNode;
            }
        }

        return $restrictedNodes;
    }

    /**
     * {@inheritdoc}
     */
    public function GetHtmlHeadIncludes()
    {
        $includes = parent::GetHtmlHeadIncludes();
        $includes[] = sprintf('<script src="%s" type="text/javascript"></script>', $this->global->GetStaticURLToWebLib('/javascript/jsTree/3.3.8/jstree.js'));
        $includes[] = sprintf('<script src="%s" type="text/javascript"></script>', $this->global->GetStaticURLToWebLib('/javascript/navigationTree.js'));
        $includes[] = sprintf('<script src="%s" type="text/javascript"></script>', $this->global->GetStaticURLToWebLib('/javascript/jquery/cookie/jquery.cookie.js'));
        $includes[] = sprintf('<link rel="stylesheet" href="%s">', $this->global->GetStaticURLToWebLib('/javascript/jsTree/3.3.8/themes/default/style.css'));
        $includes[] = sprintf('<link rel="stylesheet" href="%s">', $this->global->GetStaticURLToWebLib('/javascript/jsTree/customStyles/style.css'));

        return $includes;
    }
}