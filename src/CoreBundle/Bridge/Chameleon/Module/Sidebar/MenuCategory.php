<?php

/*
 * This file is part of the Chameleon System (https://www.chameleonsystem.com).
 *
 * (c) ESONO AG (https://www.esono.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChameleonSystem\CoreBundle\Bridge\Chameleon\Module\Sidebar;

class MenuCategory
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var string
     */
    private $iconFontCssClass;
    /**
     * @var MenuItem[]
     */
    private $menuItems;

    /**
     * @param string     $name
     * @param string     $iconFontCssClass
     * @param MenuItem[] $menuItems
     */
    public function __construct(string $name, string $iconFontCssClass, array $menuItems)
    {
        $this->name = $name;
        $this->iconFontCssClass = $iconFontCssClass;
        $this->menuItems = $menuItems;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIconFontCssClass(): string
    {
        return $this->iconFontCssClass;
    }

    public function getMenuItems(): array
    {
        return $this->menuItems;
    }
}
