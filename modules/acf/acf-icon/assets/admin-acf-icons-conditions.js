(function($){
	if (!window.acf || !acf.Condition) return;

	var __ = acf.__;

	var EqualToIcon = acf.Condition.extend({
		type: 'equalToIcon',
		operator: '==',
		label: __('Icon is equal to'),
		fieldTypes: ['frl_icon'],
		match: function(rule, field){
			return ('' + (field.val() || '')).toLowerCase() === ('' + (rule.value || '')).toLowerCase();
		},
		choices: function(fieldObject){
			return '<input type="text" placeholder="set/icon.svg" />';
		}
	});
	acf.registerConditionType(EqualToIcon);

	var NotEqualToIcon = EqualToIcon.extend({
		type: 'notEqualToIcon',
		operator: '!=',
		label: __('Icon is not equal to')
	});
	acf.registerConditionType(NotEqualToIcon);

	var HasAnyIcon = acf.Condition.extend({
		type: 'hasAnyIcon',
		operator: '!=empty',
		label: __('Has any icon'),
		fieldTypes: ['frl_icon'],
		match: function(rule, field){
			var v = field.val();
			return !!v;
		},
		choices: function(){ return '<input type="text" disabled />'; }
	});
	acf.registerConditionType(HasAnyIcon);

	var HasNoIcon = HasAnyIcon.extend({
		type: 'hasNoIcon',
		operator: '==empty',
		label: __('Has no icon'),
		match: function(rule, field){
			return !HasAnyIcon.prototype.match.apply(this, arguments);
		}
	});
	acf.registerConditionType(HasNoIcon);
})(jQuery);
