x-ui.confirm-dialog
    id="delete-employee-exception"
    :title="tr('Delete Exception')"
    :message="tr('Are you sure you want to delete this exception? This action cannot be undone.')"
    :confirmText="tr('Delete')"
    :cancelText="tr('Cancel')"
    type="danger"
    icon="fa-trash"
    confirmAction="wire:deleteException(__ID__)"
/>
