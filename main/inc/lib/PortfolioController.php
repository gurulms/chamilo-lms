<?php

/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Entity\Portfolio;
use Chamilo\CoreBundle\Entity\PortfolioCategory;
use Chamilo\CoreBundle\Entity\PortfolioComment;

/**
 * Class PortfolioController.
 */
class PortfolioController
{
    /**
     * @var string
     */
    public $baseUrl;
    /**
     * @var \Chamilo\CoreBundle\Entity\Course|null
     */
    private $course;
    /**
     * @var \Chamilo\CoreBundle\Entity\Session|null
     */
    private $session;
    /**
     * @var \Chamilo\UserBundle\Entity\User
     */
    private $owner;
    /**
     * @var int
     */
    private $currentUserId;
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;
    /**
     * @var bool
     */
    private $allowEdit;

    /**
     * PortfolioController constructor.
     */
    public function __construct()
    {
        $this->em = Database::getManager();

        $this->currentUserId = api_get_user_id();
        $ownerId = isset($_GET['user']) ? (int) $_GET['user'] : $this->currentUserId;
        $this->owner = api_get_user_entity($ownerId);
        $this->course = api_get_course_entity(api_get_course_int_id());
        $this->session = api_get_session_entity(api_get_session_id());

        $cidreq = api_get_cidreq();
        $this->baseUrl = api_get_self().'?'.($cidreq ? $cidreq.'&' : '');

        $this->allowEdit = $this->currentUserId == $this->owner->getId();

        if (isset($_GET['preview'])) {
            $this->allowEdit = false;
        }
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function addCategory()
    {
        global $interbreadcrumb;

        Display::addFlash(
            Display::return_message(get_lang('PortfolioCategoryFieldHelp'), 'info')
        );

        $form = new FormValidator('add_category', 'post', "{$this->baseUrl}&action=add_category");

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        $form->addHtmlEditor('description', get_lang('Description'), false, false, ['ToolbarSet' => 'Minimal']);
        $form->addButtonCreate(get_lang('Create'));

        if ($form->validate()) {
            $values = $form->exportValues();

            $category = new PortfolioCategory();
            $category
                ->setTitle($values['title'])
                ->setDescription($values['description'])
                ->setUser($this->owner);

            $this->em->persist($category);
            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('CategoryAdded'), 'success')
            );

            header("Location: {$this->baseUrl}");
            exit;
        }

        $interbreadcrumb[] = [
            'name' => get_lang('Portfolio'),
            'url' => $this->baseUrl,
        ];

        $actions = [];
        $actions[] = Display::url(
            Display::return_icon('back.png', get_lang('Back'), [], ICON_SIZE_MEDIUM),
            $this->baseUrl
        );

        $content = $form->returnForm();

        $this->renderView($content, get_lang('AddCategory'), $actions);
    }

    public function editCategory(PortfolioCategory $category)
    {
        global $interbreadcrumb;

        if (!$this->categoryBelongToOwner($category)) {
            api_not_allowed(true);
        }

        Display::addFlash(
            Display::return_message(get_lang('PortfolioCategoryFieldHelp'), 'info')
        );

        $form = new FormValidator(
            'edit_category',
            'post',
            $this->baseUrl."action=edit_category&id={$category->getId()}"
        );

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        $form->addHtmlEditor('description', get_lang('Description'), false, false, ['ToolbarSet' => 'Minimal']);
        $form->addButtonUpdate(get_lang('Update'));
        $form->setDefaults(
            [
                'title' => $category->getTitle(),
                'description' => $category->getDescription(),
            ]
        );

        if ($form->validate()) {
            $values = $form->exportValues();

            $category
                ->setTitle($values['title'])
                ->setDescription($values['description']);

            $this->em->persist($category);
            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('Updated'), 'success')
            );

            header("Location: $this->baseUrl");
            exit;
        }

        $interbreadcrumb[] = [
            'name' => get_lang('Portfolio'),
            'url' => $this->baseUrl,
        ];

        $actions = [];
        $actions[] = Display::url(
            Display::return_icon('back.png', get_lang('Back'), [], ICON_SIZE_MEDIUM),
            $this->baseUrl
        );

        $content = $form->returnForm();

        return $this->renderView($content, get_lang('EditCategory'), $actions);
    }

    public function showHideCategory(PortfolioCategory $category)
    {
        if (!$this->categoryBelongToOwner($category)) {
            api_not_allowed(true);
        }

        $category->setIsVisible(!$category->isVisible());

        $this->em->persist($category);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('VisibilityChanged'), 'success')
        );

        header("Location: $this->baseUrl");
        exit;
    }

    public function deleteCategory(PortfolioCategory $category)
    {
        if (!$this->categoryBelongToOwner($category)) {
            api_not_allowed(true);
        }

        $this->em->remove($category);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('CategoryDeleted'), 'success')
        );

        header("Location: $this->baseUrl");
        exit;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     */
    public function addItem()
    {
        global $interbreadcrumb;

        $categories = $this->em
            ->getRepository('ChamiloCoreBundle:PortfolioCategory')
            ->findBy(['user' => $this->owner]);

        $form = new FormValidator('add_portfolio', 'post', $this->baseUrl.'action=add_item');

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        $form->addHtmlEditor('content', get_lang('Content'), true, false, ['ToolbarSet' => 'NotebookStudent']);
        $form->addSelectFromCollection(
            'category',
            [get_lang('Category'), get_lang('PortfolioCategoryFieldHelp')],
            $categories,
            [],
            true
        );
        $form->addButtonCreate(get_lang('Create'));

        if ($form->validate()) {
            $values = $form->exportValues();
            $currentTime = new DateTime(
                api_get_utc_datetime(),
                new DateTimeZone('UTC')
            );

            $portfolio = new Portfolio();
            $portfolio
                ->setTitle($values['title'])
                ->setContent($values['content'])
                ->setUser($this->owner)
                ->setCourse($this->course)
                ->setSession($this->session)
                ->setCategory(
                    $this->em->find('ChamiloCoreBundle:PortfolioCategory', $values['category'])
                )
                ->setCreationDate($currentTime)
                ->setUpdateDate($currentTime);

            $this->em->persist($portfolio);
            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('PortfolioItemAdded'), 'success')
            );

            header("Location: $this->baseUrl");
            exit;
        }

        $interbreadcrumb[] = [
            'name' => get_lang('Portfolio'),
            'url' => $this->baseUrl,
        ];

        $actions = [];
        $actions[] = Display::url(
            Display::return_icon('back.png', get_lang('Back'), [], ICON_SIZE_MEDIUM),
            $this->baseUrl
        );

        $content = $form->returnForm();

        $this->renderView($content, get_lang('AddPortfolioItem'), $actions);
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     */
    public function editItem(Portfolio $item)
    {
        global $interbreadcrumb;

        if (!$this->itemBelongToOwner($item)) {
            api_not_allowed(true);
        }

        $categories = $this->em
            ->getRepository('ChamiloCoreBundle:PortfolioCategory')
            ->findBy(['user' => $this->owner]);

        $form = new FormValidator('edit_portfolio', 'post', $this->baseUrl."action=edit_item&id={$item->getId()}");

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        if ($item->getOrigin()) {
            if (Portfolio::TYPE_ITEM === $item->getOriginType()) {
                $origin = $this->em->find(Portfolio::class, $item->getOrigin());

                $form->addLabel(
                    sprintf(get_lang('PortfolioItemFromXUser'), $origin->getUser()->getCompleteName()),
                    Display::panel($origin->getContent())
                );
            } elseif (Portfolio::TYPE_COMMENT === $item->getOriginType()) {
                $origin = $this->em->find(PortfolioComment::class, $item->getOrigin());

                $form->addLabel(
                    sprintf(get_lang('PortfolioCommentFromXUser'), $origin->getAuthor()->getCompleteName()),
                    Display::panel($origin->getContent())
                );
            }
        }

        $form->addHtmlEditor('content', get_lang('Content'), true, false, ['ToolbarSet' => 'NotebookStudent']);
        $form->addSelectFromCollection(
            'category',
            [get_lang('Category'), get_lang('PortfolioCategoryFieldHelp')],
            $categories,
            [],
            true
        );
        $form->addButtonUpdate(get_lang('Update'));
        $form->setDefaults(
            [
                'title' => $item->getTitle(),
                'content' => $item->getContent(),
                'category' => $item->getCategory() ? $item->getCategory()->getId() : '',
            ]
        );

        if ($form->validate()) {
            $values = $form->exportValues();
            $currentTime = new DateTime(api_get_utc_datetime(), new DateTimeZone('UTC'));

            $item
                ->setTitle($values['title'])
                ->setContent($values['content'])
                ->setUpdateDate($currentTime)
                ->setCategory(
                    $this->em->find('ChamiloCoreBundle:PortfolioCategory', $values['category'])
                );

            $this->em->persist($item);
            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('ItemUpdated'), 'success')
            );

            header("Location: $this->baseUrl");
            exit;
        }

        $interbreadcrumb[] = [
            'name' => get_lang('Portfolio'),
            'url' => $this->baseUrl,
        ];
        $actions = [];
        $actions[] = Display::url(
            Display::return_icon('back.png', get_lang('Back'), [], ICON_SIZE_MEDIUM),
            $this->baseUrl
        );
        $content = $form->returnForm();

        $this->renderView($content, get_lang('EditPortfolioItem'), $actions);
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function showHideItem(Portfolio $item)
    {
        if (!$this->itemBelongToOwner($item)) {
            api_not_allowed(true);
        }

        $item->setIsVisible(
            !$item->isVisible()
        );

        $this->em->persist($item);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('VisibilityChanged'), 'success')
        );

        header("Location: $this->baseUrl");
        exit;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function deleteItem(Portfolio $item)
    {
        if (!$this->itemBelongToOwner($item)) {
            api_not_allowed(true);
        }

        $this->em->remove($item);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('ItemDeleted'), 'success')
        );

        header("Location: $this->baseUrl");
        exit;
    }

    /**
     * @throws \Exception
     */
    public function index()
    {
        $actions = [];

        if ($this->currentUserId == $this->owner->getId()) {
            if ($this->allowEdit) {
                $actions[] = Display::url(
                    Display::return_icon('add.png', get_lang('Add'), [], ICON_SIZE_MEDIUM),
                    $this->baseUrl.'action=add_item'
                );
                $actions[] = Display::url(
                    Display::return_icon('folder.png', get_lang('AddCategory'), [], ICON_SIZE_MEDIUM),
                    $this->baseUrl.'action=add_category'
                );
                $actions[] = Display::url(
                    Display::return_icon('shared_setting.png', get_lang('Preview'), [], ICON_SIZE_MEDIUM),
                    $this->baseUrl.'preview=&user='.$this->owner->getId()
                );
            } else {
                $actions[] = Display::url(
                    Display::return_icon('shared_setting_na.png', get_lang('Preview'), [], ICON_SIZE_MEDIUM),
                    $this->baseUrl
                );
            }
        }

        $form = new FormValidator('a');
        $form->addUserAvatar('user', get_lang('User'), 'medium');
        $form->setDefaults(['user' => $this->owner]);

        $criteria = [];

        if (!$this->allowEdit) {
            $criteria['isVisible'] = true;
        }

        $categories = [];

        if (!$this->course) {
            $criteria['user'] = $this->owner;

            $categories = $this->em
                ->getRepository(PortfolioCategory::class)
                ->findBy($criteria);
        }

        if ($this->course) {
            unset($criteria['user']);

            $criteria['course'] = $this->course;
            $criteria['session'] = $this->session;
        } else {
            $criteria['user'] = $this->owner;
            $criteria['category'] = null;
        }

        $items = $this->em
            ->getRepository(Portfolio::class)
            ->findBy($criteria, ['creationDate' => 'DESC']);

        $items = array_filter(
            $items,
            function (Portfolio $item) {
                if ($this->currentUserId != $item->getUser()->getId()
                    && !$item->isVisible()
                ) {
                    return false;
                }

                return true;
            }
        );

        $template = new Template(null, false, false, false, false, false, false);
        $template->assign('user', $this->owner);
        $template->assign('course', $this->course);
        $template->assign('session', $this->session);
        $template->assign('allow_edit', $this->allowEdit);
        $template->assign('portfolio', $categories);
        $template->assign('uncategorized_items', $items);

        $layout = $template->get_template('portfolio/list.html.twig');
        $content = $template->fetch($layout);

        $this->renderView($content, get_lang('Portfolio'), $actions);
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function view(Portfolio $item)
    {
        global $interbreadcrumb;

        $form = $this->createCommentForm($item);

        $commentsRepo = $this->em->getRepository(PortfolioComment::class);

        $query = $commentsRepo->createQueryBuilder('comment')
            ->where('comment.item = :item')
            ->orderBy('comment.root, comment.lft', 'ASC')
            ->setParameter('item', $item)
            ->getQuery();

        $clockIcon = Display::returnFontAwesomeIcon('clock-o', '', true);

        $commentsHtml = $commentsRepo->buildTree(
            $query->getArrayResult(),
            [
                'decorate' => true,
                'rootOpen' => '<ul class="media-list">',
                'rootClose' => '</ul>',
                'childOpen' => function ($node) use ($commentsRepo) {
                    /** @var PortfolioComment $comment */
                    $comment = $commentsRepo->find($node['id']);
                    $author = $comment->getAuthor();

                    $userPicture = UserManager::getUserPicture(
                        $comment->getAuthor()->getId(),
                        USER_IMAGE_SIZE_SMALL,
                        null,
                        [
                            'picture_uri' => $author->getPictureUri(),
                            'email' => $author->getEmail(),
                        ]
                    );

                    return '<li class="media">
                        <div class="media-left">
                            <img class="media-object thumbnail" src="'.$userPicture.'" alt="'.$author->getCompleteName().'">
                        </div>
                        <div class="media-body">';
                },
                'childClose' => '</div></li>',
                'nodeDecorator' => function ($node) use ($commentsRepo, $clockIcon) {
                    /** @var PortfolioComment $comment */
                    $comment = $commentsRepo->find($node['id']);

                    $commentActions = Display::url(
                        Display::return_icon('discuss.png', get_lang('ReplyToThisComment')),
                        '#',
                        [
                            'data-comment' => htmlspecialchars(
                                json_encode(['id' => $comment->getId()])
                            ),
                            'role' => 'button',
                            'class' => 'btn-reply-to',
                        ]
                    );
                    $commentActions .= PHP_EOL;
                    $commentActions .= Display::url(
                        Display::return_icon('copy.png', get_lang('CopyToMyPortfolio')),
                        $this->baseUrl.http_build_query(
                            [
                                'action' => 'copy',
                                'copy' => 'comment',
                                'id' => $comment->getId(),
                            ]
                        )
                    );

                    if (api_is_allowed_to_edit()) {
                        $commentActions .= Display::url(
                            Display::return_icon('copy.png', get_lang('CopyToStudentPortfolio')),
                            $this->baseUrl.http_build_query(
                                [
                                    'action' => 'teacher_copy',
                                    'copy' => 'comment',
                                    'id' => $comment->getId(),
                                ]
                            )
                        );
                    }

                    return '<p class="h4 media-heading">'.$comment->getAuthor()->getCompleteName().PHP_EOL.'<small>'
                        .$clockIcon.PHP_EOL.Display::dateToStringAgoAndLongDate($comment->getDate()).'</small>'
                        .'</p><div class="pull-right">'.$commentActions.'</div>'.$comment->getContent().PHP_EOL;
                },
            ]
        );

        $origin = null;

        if ($item->getOrigin() !== null) {
            if ($item->getOriginType() === Portfolio::TYPE_ITEM) {
                $origin = $this->em->find(Portfolio::class, $item->getOrigin());
            } elseif ($item->getOriginType() === Portfolio::TYPE_COMMENT) {
                $origin = $this->em->find(PortfolioComment::class, $item->getOrigin());
            }
        }

        $template = new Template(null, false, false, false, false, false, false);
        $template->assign('baseurl', $this->baseUrl);
        $template->assign('item', $item);
        $template->assign('origin', $origin);
        $template->assign('comments', $commentsHtml);
        $template->assign('form', $form);

        $layout = $template->get_template('portfolio/view.html.twig');
        $content = $template->fetch($layout);

        $interbreadcrumb[] = ['name' => get_lang('Portfolio'), 'url' => $this->baseUrl];

        $actions = [];
        $actions[] = Display::url(
            Display::return_icon('back.png', get_lang('Back'), [], ICON_SIZE_MEDIUM),
            $this->baseUrl
        );

        $this->renderView($content, $item->getTitle(), $actions, false);
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function copyItem(Portfolio $originItem)
    {
        $currentTime = api_get_utc_datetime(null, false, true);

        $portfolio = new Portfolio();
        $portfolio
            ->setIsVisible(false)
            ->setTitle(
                sprintf(get_lang('PortfolioItemFromXUser'), $originItem->getUser()->getCompleteName())
            )
            ->setContent('')
            ->setUser($this->owner)
            ->setOrigin($originItem->getId())
            ->setOriginType(Portfolio::TYPE_ITEM)
            ->setCourse($this->course)
            ->setSession($this->session)
            ->setCreationDate($currentTime)
            ->setUpdateDate($currentTime);

        $this->em->persist($portfolio);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('PortfolioItemAdded'), 'success')
        );

        header("Location: $this->baseUrl".http_build_query(['action' => 'edit_item', 'id' => $portfolio->getId()]));
        exit;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function copyComment(PortfolioComment $originComment)
    {
        $currentTime = api_get_utc_datetime(null, false, true);

        $portfolio = new Portfolio();
        $portfolio
            ->setIsVisible(false)
            ->setTitle(
                sprintf(get_lang('PortfolioCommentFromXUser'), $originComment->getAuthor()->getCompleteName())
            )
            ->setContent('')
            ->setUser($this->owner)
            ->setOrigin($originComment->getId())
            ->setOriginType(Portfolio::TYPE_COMMENT)
            ->setCourse($this->course)
            ->setSession($this->session)
            ->setCreationDate($currentTime)
            ->setUpdateDate($currentTime);

        $this->em->persist($portfolio);
        $this->em->flush();

        Display::addFlash(
            Display::return_message(get_lang('PortfolioItemAdded'), 'success')
        );

        header("Location: $this->baseUrl".http_build_query(['action' => 'edit_item', 'id' => $portfolio->getId()]));
        exit;
    }

    public function teacherCopyItem(Portfolio $originItem)
    {
        $actionParams = http_build_query(['action' => 'teacher_copy', 'copy' => 'item', 'id' => $originItem->getId()]);

        $form = new FormValidator('teacher_copy_portfolio', 'post', $this->baseUrl.$actionParams);

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        $form->addLabel(
            sprintf(get_lang('PortfolioItemFromXUser'), $originItem->getUser()->getCompleteName()),
            Display::panel($originItem->getContent())
        );
        $form->addHtmlEditor('content', get_lang('Content'), true, false, ['ToolbarSet' => 'NotebookStudent']);

        $urlParams = http_build_query(
            [
                'a' => 'search_user_by_course',
                'course_id' => $this->course->getId(),
                'session_id' => $this->session ? $this->session->getId() : 0,
            ]
        );
        $form->addSelectAjax(
            'students',
            get_lang('Students'),
            [],
            [
                'url' => api_get_path(WEB_AJAX_PATH)."course.ajax.php?$urlParams",
                'multiple' => true,
            ]
        );
        $form->addRule('students', get_lang('ThisFieldIsRequired'), 'required');
        $form->addButtonCreate(get_lang('Save'));

        $toolName = get_lang('CopyToStudentPortfolio');
        $content = $form->returnForm();

        if ($form->validate()) {
            $values = $form->exportValues();

            $currentTime = api_get_utc_datetime(null, false, true);

            foreach ($values['students'] as $studentId) {
                $owner = api_get_user_entity($studentId);

                $portfolio = new Portfolio();
                $portfolio
                    ->setIsVisible(false)
                    ->setTitle($values['title'])
                    ->setContent($values['content'])
                    ->setUser($owner)
                    ->setOrigin($originItem->getId())
                    ->setOriginType(Portfolio::TYPE_ITEM)
                    ->setCourse($this->course)
                    ->setSession($this->session)
                    ->setCreationDate($currentTime)
                    ->setUpdateDate($currentTime);

                $this->em->persist($portfolio);
            }

            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('PortfolioItemAddedToStudents'), 'success')
            );

            header("Location: $this->baseUrl");
            exit;
        }

        $this->renderView($content, $toolName);
    }

    public function teacherCopyComment(PortfolioComment $originComment)
    {
        $actionParams = http_build_query(['action' => 'teacher_copy', 'copy' => 'comment', 'id' => $originComment->getId()]);

        $form = new FormValidator('teacher_copy_portfolio', 'post', $this->baseUrl.$actionParams);

        if (api_get_configuration_value('save_titles_as_html')) {
            $form->addHtmlEditor('title', get_lang('Title'), true, false, ['ToolbarSet' => 'TitleAsHtml']);
        } else {
            $form->addText('title', get_lang('Title'));
            $form->applyFilter('title', 'trim');
        }

        $form->addLabel(
            sprintf(get_lang('PortfolioCommentFromXUser'), $originComment->getAuthor()->getCompleteName()),
            Display::panel($originComment->getContent())
        );
        $form->addHtmlEditor('content', get_lang('Content'), true, false, ['ToolbarSet' => 'NotebookStudent']);

        $urlParams = http_build_query(
            [
                'a' => 'search_user_by_course',
                'course_id' => $this->course->getId(),
                'session_id' => $this->session ? $this->session->getId() : 0,
            ]
        );
        $form->addSelectAjax(
            'students',
            get_lang('Students'),
            [],
            [
                'url' => api_get_path(WEB_AJAX_PATH)."course.ajax.php?$urlParams",
                'multiple' => true,
            ]
        );
        $form->addRule('students', get_lang('ThisFieldIsRequired'), 'required');
        $form->addButtonCreate(get_lang('Save'));

        $toolName = get_lang('CopyToStudentPortfolio');
        $content = $form->returnForm();

        if ($form->validate()) {
            $values = $form->exportValues();

            $currentTime = api_get_utc_datetime(null, false, true);

            foreach ($values['students'] as $studentId) {
                $owner = api_get_user_entity($studentId);

                $portfolio = new Portfolio();
                $portfolio
                    ->setIsVisible(false)
                    ->setTitle($values['title'])
                    ->setContent($values['content'])
                    ->setUser($owner)
                    ->setOrigin($originComment->getId())
                    ->setOriginType(Portfolio::TYPE_COMMENT)
                    ->setCourse($this->course)
                    ->setSession($this->session)
                    ->setCreationDate($currentTime)
                    ->setUpdateDate($currentTime);

                $this->em->persist($portfolio);
            }

            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('PortfolioItemAddedToStudents'), 'success')
            );

            header("Location: $this->baseUrl");
            exit;
        }

        $this->renderView($content, $toolName);
    }

    /**
     * @param bool $showHeader
     */
    private function renderView(string $content, string $toolName, array $actions = [], $showHeader = true)
    {
        global $this_section;

        $this_section = $this->course ? SECTION_COURSES : SECTION_SOCIAL;

        $view = new Template($toolName);

        if ($showHeader) {
            $view->assign('header', $toolName);
        }

        $actionsStr = '';

        if ($this->course) {
            $actionsStr .= Display::return_introduction_section(TOOL_PORTFOLIO);
        }

        if ($actions) {
            $actions = implode(PHP_EOL, $actions);

            $actionsStr .= Display::toolbarAction('portfolio-toolbar', [$actions]);
        }

        $view->assign('baseurl', $this->baseUrl);
        $view->assign('actions', $actionsStr);

        $view->assign('content', $content);
        $view->display_one_col_template();
    }

    private function categoryBelongToOwner(PortfolioCategory $category): bool
    {
        if ($category->getUser()->getId() != $this->owner->getId()) {
            return false;
        }

        return true;
    }

    private function itemBelongToOwner(Portfolio $item): bool
    {
        if ($this->session && $item->getSession()->getId() != $this->session->getId()) {
            return false;
        }

        if ($this->course && $item->getCourse()->getId() != $this->course->getId()) {
            return false;
        }

        if ($item->getUser()->getId() != $this->owner->getId()) {
            return false;
        }

        return true;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function createCommentForm(Portfolio $item): string
    {
        $formAction = $this->baseUrl.http_build_query(['action' => 'view', 'id' => $item->getId()]);

        $form = new FormValidator('frm_comment', 'post', $formAction);
        $form->addHtmlEditor('content', get_lang('Comments'), true, false, ['ToolbarSet' => 'Minimal']);
        $form->addHidden('item', $item->getId());
        $form->addHidden('parent', 0);
        $form->applyFilter('content', 'trim');
        $form->addButtonSave(get_lang('Save'));

        if ($form->validate()) {
            $values = $form->exportValues();

            $parentComment = $this->em->find(PortfolioComment::class, $values['parent']);

            $comment = new PortfolioComment();
            $comment
                ->setAuthor($this->owner)
                ->setParent($parentComment)
                ->setContent($values['content'])
                ->setDate(api_get_utc_datetime(null, false, true))
                ->setItem($item);

            $this->em->persist($comment);
            $this->em->flush();

            Display::addFlash(
                Display::return_message(get_lang('CommentAdded'), 'success')
            );

            header("Location: $formAction");
            exit;
        }

        return $form->returnForm();
    }
}