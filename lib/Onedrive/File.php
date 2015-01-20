<?php
namespace Onedrive;

/*
 * A File instance is an Object instance referencing a OneDrive file. It may
 * have content but may not contain other OneDrive objects.
 */
class File extends Object
{

    /**
     * Fetches the content of the OneDrive file referenced by this File instance.
     *
     * @return (string) The content of the OneDrive file referenced by this File
     *         instance.
     */
    // TODO: should somewhat return the content-type as well; this information is
    // not disclosed by OneDrive
    public function fetchContent()
    {
        return $this->_client->apiGet($this->_id . '/content');
    }

    /**
     * Copies the OneDrive file referenced by this File instance into another
     * OneDrive folder.
     *
     * @param
     *            (null|string) The unique ID of the OneDrive folder into which to
     *            copy the OneDrive file referenced by this File instance, or null to
     *            copy it in the OneDrive root folder. Default: null.
     */
    public function copy($destinationId = null)
    {
        $this->_client->copyFile($this->_id, $destinationId);
    }
}
