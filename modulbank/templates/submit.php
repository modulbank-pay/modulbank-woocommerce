<!DOCTYPE html>
<html>
<head>
	<title>...</title>
	<script type="text/javascript">
	window.onload = function() {
		document.getElementById('autosubmit-form').submit();
	};
	</script>
</head>
<body>
	<form action="<?php echo $ff->get_url(); ?>" method="post" id="autosubmit-form">
	    <?php echo FPaymentsForm::array_to_hidden_fields($data); ?>
	    <input type="submit" value="<?php _e('Continue'); ?>">
	</form>
</body>
</html>
