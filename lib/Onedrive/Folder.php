<?php
namespace Onedrive;

/*
 * A Folder instance is an Object instance referencing to a OneDrive folder. It
 * may contain other OneDrive objects but may not have content.
 */
class Folder extends Object
{

    /**
     * Gets the objects in the OneDrive folder referenced by this Folder instance.
     *
     * @return (array) The objects in the OneDrive folder referenced by this
     *         Folder instance, as Object instances.
     */
    public function fetchObjects()
    {
        return $this->_client->fetchObjects($this->_id);
    }

    /**
     * Creates a folder in the OneDrive folder referenced by this Folder instance.
     *
     * @param (string) $name
     *            - The name of the OneDrive folder to be created.
     * @param (null|string) $description
     *            - The description of the OneDrive folder
     *            to be created, or null to create it without a description. Default:
     *            null.
     * @return (Folder) The folder created, as a Folder instance.
     */
    public function createFolder($name, $description = null)
    {
        return $this->_client->createFolder($name, $this->_id, $description);
    }

    /**
     * Creates a file in the OneDrive folder referenced by this Folder instance.
     *
     * @param (string) $name
     *            - The name of the OneDrive file to be created.
     * @param (string) $content
     *            - The content of the OneDrive file to be created.
     *            Default: ''.
     * @return (File) The file created, as a File instance.
     *         @throw (\Exception) Thrown on I/O errors.
     */
    public function createFile($name, $content = '')
    {
        return $this->_client->createFile($name, $this->_id, $content);
    }
}
