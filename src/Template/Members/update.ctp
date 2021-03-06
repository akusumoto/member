<?= $this->Cell('Menu') ?>
<div class="members form large-10 medium-9 columns">
    <?= $this->Form->create($member); ?>
    <fieldset>
        <legend><?= __('Update Information') ?></legend>
        <h2><?= h($member->nickname) ?></h2>
        <?php
            echo $this->Form->input('part_id', ['options' => $parts]);
            echo $this->Form->input('nickname');
            echo $this->Form->input('name');
            echo $this->Form->input('account');
            echo $this->Form->input('email');
            echo $this->Form->input('home_address');
            echo $this->Form->input('work_address');
            echo $this->Form->input('member_type_id', ['options' => $memberTypes]);
            echo $this->Form->input('emergency_phone');
            echo $this->Form->input('note');
        ?>
    </fieldset>
    <?= $this->Form->button(__('Update')) ?>
    <?= $this->Form->end() ?>
</div>
